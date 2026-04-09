<?php

// app/Http/Controllers/Api/OrderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with(['table', 'user', 'items.menuItem'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(Order $order)
    {
        return response()->json($order->load(['table', 'items.menuItem', 'payments']));
    }

    public function store(Request $request)
    {
        $request->validate([
            'table_id' => 'required|exists:tables,id',
        ]);

        $table = Table::findOrFail($request->table_id);

        if ($table->status !== 'free') {
            return response()->json(['error' => 'Table non disponible'], 422);
        }

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'table_id' => $table->id,
            'user_id' => $request->user()->id,
            'status' => 'open',
        ]);

        $table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

        return response()->json($order->load('items'), 201);
    }

    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'menu_item_id' => 'required|exists:menu_items,id',
            'quantity' => 'integer|min:1',
        ]);

        $menuItem = MenuItem::findOrFail($request->menu_item_id);

        $orderItem = $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $menuItem->id,
            'item_name' => $menuItem->name,
            'quantity' => $request->quantity ?? 1,
            'unit_price' => $menuItem->price,
            'total_price' => $menuItem->price * ($request->quantity ?? 1),
            'kitchen_status' => 'pending',
        ]);

        $order->recalculate();

        return response()->json($orderItem, 201);
    }

    public function removeItem(Order $order, $itemId)
    {
        $order->items()->where('id', $itemId)->delete();
        $order->recalculate();

        return response()->json(['message' => 'Item supprimé']);
    }

    public function confirm(Order $order)
    {
        $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

        foreach ($order->items as $item) {
            $item->update(['kitchen_status' => 'pending']);
        }

        return response()->json(['message' => 'Commande confirmée']);
    }

    public function cancel(Order $order)
    {
        $order->update(['status' => 'cancelled']);
        $order->table->update(['status' => 'free', 'current_order_id' => null]);

        return response()->json(['message' => 'Commande annulée']);
    }

    public function sendToKitchen(Order $order)
    {
        $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

        return response()->json(['message' => 'Envoyé en cuisine']);
    }
}
