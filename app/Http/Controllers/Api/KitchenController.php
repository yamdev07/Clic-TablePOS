<?php

// app/Http/Controllers/Api/KitchenController.php

namespace App\Http\Controllers\Api;

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

        return response()->json(['message' => 'Préparation commencée']);
    }

    public function markReady(OrderItem $item)
    {
        $item->update(['kitchen_status' => 'ready']);

        return response()->json(['message' => 'Plat prêt']);
    }

    public function markServed(OrderItem $item)
    {
        $item->update(['kitchen_status' => 'served']);

        return response()->json(['message' => 'Plat servi']);
    }

    public function markOutOfStock(OrderItem $item)
    {
        // Mark the underlying menu item as unavailable (rupture de stock)
        $item->menuItem()->update(['is_available' => false]);

        // Also remove this item from the kitchen queue (cancel it)
        $item->update(['kitchen_status' => 'served']);

        return response()->json(['message' => 'Rupture signalée — plat retiré du menu']);
    }
}
