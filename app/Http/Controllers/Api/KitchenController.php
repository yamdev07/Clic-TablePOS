<?php

namespace App\Http\Controllers\Api;

use App\Events\KitchenOrderUpdated;
use App\Events\OrderStatusChanged;
use App\Events\OutOfStockReported;
use App\Events\WaiterCallRequested;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    private function ensureOrderBelongsToRestaurant(Request $request, Order $order): void
    {
        abort_unless(
            (string) $order->restaurant_id === (string) $request->user()->restaurant_id,
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

    private function ensureItemBelongsToRestaurant(Request $request, OrderItem $item): void
    {
        abort_unless(
            (string) $item->order->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    public function startOrderCooking(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureKitchenRole($request);

        $items = DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedOrder->status === 'in_progress'
                    || ($lockedOrder->status === 'open' && $lockedOrder->confirmed_at !== null),
                422,
                'Seules les commandes en attente de préparation peuvent être lancées.'
            );

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
            $lockedOrder->update(['status' => 'in_progress']);

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

            abort_if(
                $items->contains(fn ($item) => $item->kitchen_status === 'pending'),
                422,
                "La préparation n'est pas lancée pour tous les plats de cette commande."
            );

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

            $lockedOrder->update(['status' => 'ready']);

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
        $this->ensureKitchenRole($request);

        $orders = Order::where('restaurant_id', $request->user()->restaurant_id)
            ->where(function ($q) {
                // Commandes envoyées en cuisine : in_progress, ready,
                // ou open avec confirmed_at (envoyées mais pas encore lancées)
                $q->whereIn('status', ['in_progress', 'ready'])
                    ->orWhere(fn ($q) => $q->where('status', 'open')->whereNotNull('confirmed_at'));
            })
            ->whereHas('items', function ($query) {
                $query->where(function ($q) {
                    $q->whereIn('kitchen_status', Order::KITCHEN_VISIBLE_ITEM_STATUSES)
                        ->orWhereNotNull('out_of_stock_at');
                });
            })
            ->with(['table', 'items.menuItem', 'items.options'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json(
            $orders->map(fn ($order) => $order->toKitchenArray())->values()
        );
    }

    public function markOutOfStock(Request $request, OrderItem $item)
    {
        $this->ensureItemBelongsToRestaurant($request, $item);
        $this->ensureKitchenRole($request);

              $result = DB::transaction(function () use ($request, $item) {
            $lockedOrder = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $lockedItem = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (
                $lockedOrder->status !== 'in_progress'
                || ! in_array($lockedItem->kitchen_status, ['pending', 'cooking'], true)
                || $lockedItem->out_of_stock_at !== null
            ) {
                return null;
            }

            $lockedItem->update([
                'out_of_stock_at' => now(),
                'kitchen_status' => 'served',
            ]);

                       // Le plat quitte le menu. Une rupture déjà déclarée garde son auteur, son motif et son heure.
            MenuItem::whereKey($lockedItem->menu_item_id)->update(['is_available' => false]);
            MenuItem::whereKey($lockedItem->menu_item_id)
                ->whereNull('out_of_stock_at')
                ->update([
                    'out_of_stock_at' => now(),
                    'out_of_stock_reason' => 'Signalé depuis la cuisine',
                    'out_of_stock_by' => $request->user()->id,
                ]);

            $lockedOrder->recalculate();

            return [$lockedOrder, $lockedItem];
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Ce plat ne peut pas être signalé en rupture.',
            ], 422);
        }

        [$order, $reported] = $result;

        $this->tryBroadcast(
            fn () => broadcast(
                new KitchenOrderUpdated($order->fresh(['items', 'table']))
            )->toOthers()
        );
        $this->tryBroadcast(
            fn () => broadcast(
                new OutOfStockReported($order->fresh(['table']), $reported->fresh())
            )->toOthers()
        );

        return response()->json([
            'message' => 'Rupture signalée — plat retiré du menu.',
        ]);
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
