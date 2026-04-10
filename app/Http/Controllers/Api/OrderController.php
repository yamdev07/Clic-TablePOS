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
        try {
            $request->validate([
                'table_id' => 'required|exists:tables,id',
            ]);

            $table = Table::findOrFail($request->table_id);

            if ($table->status !== 'free') {
                return response()->json([
                    'success' => false,
                    'message' => 'Table non disponible',
                    'status' => $table->status
                ], 422);
            }

            $order = Order::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $request->user()->restaurant_id,
                'table_id' => $table->id,
                'user_id' => $request->user()->id,
                'status' => 'open',
            ]);

            $table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

            return response()->json([
                'success' => true,
                'data' => $order->load('items')
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function addItem(Request $request, Order $order)
    {
        try {
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

            return response()->json([
                'success' => true,
                'data' => $orderItem
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function removeItem(Order $order, $itemId)
    {
        try {
            $order->items()->where('id', $itemId)->delete();
            $order->recalculate();

            return response()->json([
                'success' => true,
                'message' => 'Item supprimé'
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function confirm(Order $order)
    {
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

            foreach ($order->items as $item) {
                $item->update(['kitchen_status' => 'pending']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Commande confirmée'
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel(Order $order)
    {
        try {
            $order->update(['status' => 'cancelled']);
            
            if ($order->table) {
                $order->table->update(['status' => 'free', 'current_order_id' => null]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée'
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function sendToKitchen(Order $order)
    {
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Commande envoyée en cuisine'
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        try {
            $request->validate([
                'status' => 'required|in:open,in_progress,ready,served,paid,cancelled'
            ]);

            $order->update(['status' => $request->status]);

            // Si la commande est payée, libérer la table
            if ($request->status === 'paid' && $order->table) {
                $order->table->update(['status' => 'free', 'current_order_id' => null]);
            }

            return response()->json([
                'success' => true,
                'data' => $order
            ], 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}