<?php

// app/Http/Controllers/Api/KitchenController.php

namespace App\Http\Controllers\Api;

use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\LogService;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
    /**
     * Plats à traiter : commandes envoyées en cuisine (ouvertes, en préparation ou prêtes).
     * Une commande « open » n'apparaît qu'une fois envoyée (confirmed_at renseigné).
     */
    public function pendingOrders(Request $request)
    {
        $items = OrderItem::whereHas('order', function ($q) use ($request) {
            $q->where('restaurant_id', $request->user()->restaurant_id)
                ->where(function ($q) {
                    $q->whereIn('status', ['in_progress', 'ready'])
                        ->orWhere(fn ($q) => $q->where('status', 'open')->whereNotNull('confirmed_at'));
                });
        })
            ->with(['order.table', 'menuItem'])
            ->whereIn('kitchen_status', ['pending', 'cooking', 'ready'])
            ->orderBy('created_at')
            ->get();

        return response()->json($items);
    }

    public function startCooking(OrderItem $item)
    {
        return $this->transition($item, 'cooking', 'Préparation commencée');
    }

    public function markReady(OrderItem $item)
    {
        return $this->transition($item, 'ready', 'Plat prêt');
    }

    public function markServed(OrderItem $item)
    {
        return $this->transition($item, 'served', 'Plat servi');
    }

    /** Rupture : le plat est retiré du menu et de la commande (il n'est pas facturé). */
    public function markOutOfStock(OrderItem $item, Request $request)
    {
        $order = $item->order;

        $item->menuItem()->update(['is_available' => false]);
        $item->delete();
        $order->recalculate();

        LogService::log($request, 'kitchen.out_of_stock',
            "Rupture : \"{$item->item_name}\" retiré de la commande #{$order->order_number}",
            'order', $order->id);

        $this->syncOrder($order);

        return response()->json(['message' => 'Rupture signalée — plat retiré du menu et de la commande']);
    }

    private function transition(OrderItem $item, string $status, string $message)
    {
        $item->update(['kitchen_status' => $status]);
        $this->tryBroadcast(fn () => broadcast(new OrderItemStatusChanged($item->load('order.table'))));

        $order = $this->syncOrder($item->order);

        return response()->json([
            'message'      => $message,
            'order_status' => $order->status,
        ]);
    }

    /** Fait suivre le statut de la commande et le diffuse s'il a changé. */
    private function syncOrder($order)
    {
        $order->refresh();
        $oldStatus = $order->syncStatusFromItems();

        if ($oldStatus !== null) {
            $this->tryBroadcast(fn () => broadcast(new OrderStatusChanged($order, $oldStatus)));
        }

        return $order;
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
