<?php

// app/Http/Controllers/Api/KitchenController.php

namespace App\Http\Controllers\Api;

use App\Events\OrderItemStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    private function ensureItemBelongsToRestaurant(
        Request $request,
        OrderItem $item
    ): void {
        abort_unless(
            (string) $item->order->restaurant_id ===
            (string) $request->user()->restaurant_id,
            404
        );
    }

    public function pendingOrders(Request $request)
    {
        $items = OrderItem::whereHas('order', function ($q) use ($request) {
            $q->where('restaurant_id', $request->user()->restaurant_id)
                ->whereIn('status', ['in_progress', 'ready']);
        })
            ->with(['order.table', 'menuItem'])
            ->whereIn('kitchen_status', ['pending', 'cooking', 'ready'])
            ->orderBy('created_at')
            ->get();

        return response()->json($items);
    }

    public function startCooking(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);

        $error = DB::transaction(function () use ($item) {
            // Même ordre de verrous que l'annulation : la commande d'abord, puis le plat
            $order = Order::whereKey($item->order_id)->lockForUpdate()->first();

            if (in_array($order->status, ['cancelled', 'paid'], true)) {
                return "Cette commande n'est plus active : impossible de commencer la préparation.";
            }

            $locked = OrderItem::whereKey($item->id)->lockForUpdate()->first();
            $locked->update([
                'kitchen_status' => 'cooking',
                'started_at' => $locked->started_at ?? now(),
            ]);

            return null;
        });

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $item->refresh();
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Préparation commencée']);
    }

    public function markReady(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);

        $item->update([
            'kitchen_status' => 'ready',
            'ready_at' => $item->ready_at ?? now(),
        ]);

        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table'))));

        return response()->json(['message' => 'Plat prêt']);
    }

    public function markServed(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);
        $item->update(['kitchen_status' => 'served']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Plat servi']);
    }

    public function markOutOfStock(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);
        $item->menuItem()->update(['is_available' => false]);
        $item->update(['kitchen_status' => 'served']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Rupture signalée — plat retiré du menu']);
    }

    /** Broadcast silencieux — ne casse pas la réponse si Reverb est absent. */
    private function tryBroadcast(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Reverb indisponible — le polling frontend compensera
        }
    }
}
