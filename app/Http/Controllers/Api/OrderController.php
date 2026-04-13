<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Table;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with([
                'table:id,number,capacity',
                'user:id,name,role',
                'items:id,order_id,menu_item_id,item_name,quantity,unit_price,total_price,kitchen_status',
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('created_at', 'desc')
            ->paginate(30);

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
                    'status'  => $table->status,
                ], 422);
            }

            $order = Order::create([
                'id'            => (string) Str::uuid(),
                'restaurant_id' => $request->user()->restaurant_id,
                'table_id'      => $table->id,
                'user_id'       => $request->user()->id,
                'status'        => 'open',
            ]);

            $table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

            LogService::log($request, 'order.created',
                "Commande #{$order->order_number} créée — Table {$table->number}",
                'order', $order->id);

            return response()->json(['success' => true, 'data' => $order->load('items')], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function addItem(Request $request, Order $order)
    {
        try {
            $request->validate([
                'menu_item_id'          => 'required|exists:menu_items,id',
                'quantity'              => 'integer|min:1',
                'special_instructions'  => 'nullable|string|max:500',
            ]);

            $menuItem  = MenuItem::findOrFail($request->menu_item_id);
            $quantity  = $request->quantity ?? 1;

            $orderItem = $order->items()->create([
                'id'                    => (string) Str::uuid(),
                'menu_item_id'          => $menuItem->id,
                'item_name'             => $menuItem->name,
                'quantity'              => $quantity,
                'unit_price'            => $menuItem->price,
                'total_price'           => $menuItem->price * $quantity,
                'kitchen_status'        => 'pending',
                'special_instructions'  => $request->special_instructions,
            ]);

            $order->recalculate();

            LogService::log($request, 'order.item_added',
                "Ajout x{$quantity} {$menuItem->name} → commande #{$order->order_number}",
                'order', $order->id);

            return response()->json(['success' => true, 'data' => $orderItem], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function removeItem(Order $order, $itemId, Request $request)
    {
        try {
            $item = $order->items()->where('id', $itemId)->first();
            $itemName = $item?->item_name ?? 'article';

            $order->items()->where('id', $itemId)->delete();
            $order->recalculate();

            LogService::log($request, 'order.item_removed',
                "Suppression de \"{$itemName}\" — commande #{$order->order_number}",
                'order', $order->id);

            return response()->json(['success' => true, 'message' => 'Item supprimé']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function confirm(Order $order, Request $request)
    {
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

            foreach ($order->items as $item) {
                $item->update(['kitchen_status' => 'pending']);
            }

            LogService::log($request, 'order.confirmed',
                "Commande #{$order->order_number} confirmée",
                'order', $order->id);

            return response()->json(['success' => true, 'message' => 'Commande confirmée']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel(Order $order, Request $request)
    {
        try {
            $order->update(['status' => 'cancelled']);

            if ($order->table) {
                $order->table->update(['status' => 'free', 'current_order_id' => null]);
            }

            LogService::log($request, 'order.cancelled',
                "Commande #{$order->order_number} annulée",
                'order', $order->id);

            return response()->json(['success' => true, 'message' => 'Commande annulée']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendToKitchen(Order $order, Request $request)
    {
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);

            LogService::log($request, 'order.sent_to_kitchen',
                "Commande #{$order->order_number} envoyée en cuisine",
                'order', $order->id);

            return response()->json(['success' => true, 'message' => 'Commande envoyée en cuisine']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        try {
            $request->validate([
                'status' => 'required|in:open,in_progress,ready,served,paid,cancelled',
            ]);

            $oldStatus = $order->status;
            $order->update(['status' => $request->status]);

            if (in_array($request->status, ['paid', 'cancelled']) && $order->table) {
                $order->table->update(['status' => 'free', 'current_order_id' => null]);
            }

            LogService::log($request, 'order.status_updated',
                "Commande #{$order->order_number} : {$oldStatus} → {$request->status}",
                'order', $order->id,
                ['status' => $oldStatus], ['status' => $request->status]);

            return response()->json(['success' => true, 'data' => $order]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
