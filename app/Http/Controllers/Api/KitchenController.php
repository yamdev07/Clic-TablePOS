<?php

// app/Http/Controllers/Api/KitchenController.php

namespace App\Http\Controllers\Api;

use App\Events\KitchenOrderUpdated;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Events\WaiterCallRequested;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    private function ensureOrderBelongsToRestaurant(
        Request $request,
        Order $order
    ): void {
        abort_unless(
            (string) $order->restaurant_id ===
            (string) $request->user()->restaurant_id,
            404
        );
    }

    private function ensureKitchenRole(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'kitchen'], true),
            403,
            "Seuls le cuisinier, le gérant et l'administrateur peuvent effectuer cette action."
        );
    }

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

    public function startOrderCooking(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureKitchenRole($request);

        $items = DB::transaction(function () use ($order) {
            // Même ordre de verrous que l'annulation : la commande d'abord, puis ses plats
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedOrder->status === 'in_progress',
                422,
                'Seules les commandes en attente de préparation peuvent être lancées.'
            );

            // Seuls les plats en attente sont lancés : les autres (par exemple une rupture) ne sont pas touchés
            $pending = OrderItem::where('order_id', $lockedOrder->id)
                ->where('kitchen_status', 'pending')
                ->lockForUpdate()
                ->get();

            abort_if(
                $pending->isEmpty(),
                422,
                'Cette commande ne contient aucun plat en attente de préparation.'
            );

            foreach ($pending as $item) {
                $item->update([
                    'kitchen_status' => 'cooking',
                    'started_at' => $item->started_at ?? now(),
                ]);
            }

            return $pending;
        });

        $order->refresh();

        $this->tryBroadcast(
            fn () => broadcast(
                new KitchenOrderUpdated($order->fresh(['items', 'table']))
            )->toOthers()
        );

        return response()->json([
            'success' => true,
            'message' => 'La préparation de toute la commande a commencé.',
        ]);
    }

    public function markOrderReady(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureKitchenRole($request);

        [$items, $oldStatus] = DB::transaction(function () use ($order) {

            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedOrder->status === 'in_progress',
                422,
                'Cette commande ne peut pas encore être marquée comme prête.'
            );

            $items = OrderItem::where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->get();

            // La préparation doit avoir été lancée pour tous les plats
            abort_if(
                $items->contains(fn ($item) => $item->kitchen_status === 'pending'),
                422,
                "La préparation n'est pas lancée pour tous les plats de cette commande."
            );

            // Seuls les plats en cuisson deviennent prêts : les autres (une rupture) ne sont pas touchés
            $cooking = $items->where('kitchen_status', 'cooking');

            abort_if(
                $cooking->isEmpty(),
                422,
                'Cette commande ne contient aucun plat en cours de préparation.'
            );

            foreach ($cooking as $item) {
                $item->update([
                    'kitchen_status' => 'ready',
                    'ready_at' => $item->ready_at ?? now(),
                ]);
            }

            $oldStatus = $lockedOrder->status;

            $lockedOrder->update([
                'status' => 'ready',
            ]);

            return [$cooking, $oldStatus];
        });

        $order->refresh();

        $this->tryBroadcast(
            fn () => broadcast(
                new KitchenOrderUpdated($order->fresh(['items', 'table']))
            )->toOthers()
        );

        $this->tryBroadcast(
            fn () => broadcast(
                new OrderStatusChanged($order, $oldStatus)
            )->toOthers()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tous les plats de la commande sont prêts.',
            'data' => $order,
        ]);
    }

    public function callWaiter(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureKitchenRole($request);

        abort_unless(
            $order->status === 'ready',
            422,
            'Le serveur ne peut être appelé que lorsque tous les plats sont prêts.'
        );

        $this->tryBroadcast(
            fn () => broadcast(
                new WaiterCallRequested($order)
            )->toOthers()
        );

        return response()->json([
            'success' => true,
            'message' => 'Le serveur a été appelé.',
        ]);
    }
    public function pendingOrders(Request $request)
    {
        $orders = Order::where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('status', ['in_progress', 'ready'])
            ->whereHas('items', function ($query) {
                $query->whereIn('kitchen_status', Order::KITCHEN_VISIBLE_ITEM_STATUSES);
            })
            ->with(['table', 'items'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json(
            $orders->map(fn ($order) => $order->toKitchenArray())->values()
        );
    }
    public function startCooking(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);
        $this->ensureKitchenRole($request);

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
        $this->ensureKitchenRole($request);

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
        $this->ensureKitchenRole($request);
        $item->update(['kitchen_status' => 'served']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Plat servi']);
    }

    public function markOutOfStock(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);
        $this->ensureKitchenRole($request);
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
