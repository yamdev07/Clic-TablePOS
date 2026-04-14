<?php

// app/Http/Controllers/Api/KitchenController.php

namespace App\Http\Controllers\Api;

use App\Events\OrderItemStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
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

    public function startCooking(OrderItem $item)
    {
        $item->update(['kitchen_status' => 'cooking']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Préparation commencée']);
    }

    public function markReady(OrderItem $item)
    {
        $item->update(['kitchen_status' => 'ready']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table'))));

        return response()->json(['message' => 'Plat prêt']);
    }

    public function markServed(OrderItem $item)
    {
        $item->update(['kitchen_status' => 'served']);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table')))->toOthers());

        return response()->json(['message' => 'Plat servi']);
    }

    public function markOutOfStock(OrderItem $item)
    {
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
