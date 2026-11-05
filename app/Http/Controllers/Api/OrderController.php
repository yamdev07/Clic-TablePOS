<?php

namespace App\Http\Controllers\Api;

use App\Events\KitchenOrderUpdated;
use App\Events\NewOrderReceived;
use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Table;
use App\Services\LogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private const CANCEL_ROLES = ['admin', 'manager', 'waiter'];

    private const STATUS_ROLES = ['admin', 'manager', 'waiter'];

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

    private function ensureOrderIsEditable(Order $order): void
    {
        if ($order->status !== 'open') {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Impossible de modifier cette commande : elle a déjà été envoyée en cuisine.',
            ], 422));
        }
    }

    private function ensureOrderCanBeSent(Order $order): void
    {
        if ($order->status !== 'open') {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Cette commande ne peut plus être envoyée en cuisine.',
            ], 422));
        }
    }

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

    public function show(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);

        return response()->json($order->load(['table', 'items.menuItem', 'payments']));
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'table_id' => 'required|exists:tables,id',
                'guest_count' => 'nullable|integer|min:1|max:99',
            ]);
            $table = Table::findOrFail($request->table_id);

            if ((string) $table->restaurant_id !==
                (string) $request->user()->restaurant_id) {
                return response()->json([
                    'message' => 'Cette table appartient à un autre restaurant.',
                ], 404);
            }
            if (! in_array($table->status, ['free', 'occupied'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table non disponible',
                    'status' => $table->status,
                ], 422);
            }

            $order = Order::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $request->user()->restaurant_id,
                'table_id' => $table->id,
                'user_id' => $request->user()->id,
                'status' => 'open',
                'guest_count' => $request->guest_count,
            ]);

            $table->update([
                'status' => 'occupied',
                'current_order_id' => $table->current_order_id ?? $order->id,
            ]);

            LogService::log($request, 'order.created',
                "Commande #{$order->order_number} créée — Table {$table->number}",
                'order', $order->id);

            return response()->json(['success' => true, 'data' => $order->load('items')], 201);

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function addItem(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureOrderIsEditable($order);
        try {
            $request->validate([
                'menu_item_id' => 'required|exists:menu_items,id',
                'quantity' => 'integer|min:1',
                'special_instructions' => 'nullable|string|max:500',
            ]);

            $menuItem = MenuItem::findOrFail($request->menu_item_id);

            if ((string) $menuItem->restaurant_id !==
                (string) $request->user()->restaurant_id) {
                return response()->json([
                    'message' => 'Cet article appartient à un autre restaurant.',
                ], 404);
            }
            $quantity = $request->quantity ?? 1;

            $orderItem = $order->items()->create([
                'id' => (string) Str::uuid(),
                'menu_item_id' => $menuItem->id,
                'item_name' => $menuItem->name,
                'quantity' => $quantity,
                'unit_price' => $menuItem->price,
                'total_price' => $menuItem->price * $quantity,
                'kitchen_status' => 'pending',
                'special_instructions' => $request->special_instructions,
            ]);

            $order->recalculate();

            LogService::log($request, 'order.item_added',
                "Ajout x{$quantity} {$menuItem->name} → commande #{$order->order_number}",
                'order', $order->id);

            return response()->json(['success' => true, 'data' => $orderItem], 201);

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function removeItem(Request $request, Order $order, $itemId)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureOrderIsEditable($order);
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

    public function confirm(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureOrderCanBeSent($order);
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);
            $order->table?->syncCurrentOrder();

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

    public function cancel(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);

        return $this->cancelOrder($request, $order);
    }

    public function sendToKitchen(Request $request, Order $order)
    {
        $this->ensureOrderBelongsToRestaurant($request, $order);
        $this->ensureOrderCanBeSent($order);
        try {
            $order->update(['status' => 'in_progress', 'confirmed_at' => now()]);
            $order->table?->syncCurrentOrder();

            $this->tryBroadcast(fn () => broadcast(new NewOrderReceived($order))->toOthers());

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
        $this->ensureOrderBelongsToRestaurant($request, $order);
        abort_unless(
            in_array($request->user()->role, self::STATUS_ROLES, true),
            403,
            "Le cuisinier ne peut pas modifier directement le statut global d'une commande."
        );
        if ($request->input('status') === 'cancelled') {
            return $this->cancelOrder($request, $order);
        }
        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cette commande est terminée : son statut ne peut plus changer.',
            ], 422);
        }
        if ($request->input('status') === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Une commande passe en payée uniquement par un paiement.',
            ], 422);
        }
        if ($request->input('status') === 'in_progress' && $order->status === 'open') {
            return $this->sendToKitchen($request, $order);
        }

        try {
            $request->validate([
                'status' => 'required|in:open,in_progress,ready,served,paid,cancelled',

            ]);
            $allowedTransitions = [
                'open' => 'in_progress',
                'in_progress' => 'ready',
                'ready' => 'served',
            ];

            if ($order->status === 'in_progress' && $request->status === 'ready') {
                return response()->json([
                    'success' => false,
                    'message' => 'Une commande est marquée prête depuis la cuisine, pas par cette route.',
                ], 422);
            }

            if (($allowedTransitions[$order->status] ?? null) !== $request->status) {
                $message = $request->status === 'open'
                    ? 'Une commande envoyée en cuisine ne peut pas redevenir ouverte.'
                    : 'Transition de statut invalide. Respectez l’ordre : open → in_progress → ready → served.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }
            $oldStatus = $order->status;

            DB::transaction(function () use ($order, $request) {
                $order->update(['status' => $request->status]);

                if ($request->status === 'served') {
                    $order->items()->update([
                        'kitchen_status' => 'served',
                    ]);
                }
            });

            if (in_array($request->status, ['paid', 'cancelled']) && $order->table) {
                $order->table->update(['status' => 'free', 'current_order_id' => null]);
            }

            $order->refresh();

            if ($request->status === 'served') {
                $this->tryBroadcast(
                    fn () => broadcast(
                        new KitchenOrderUpdated($order->fresh(['items', 'table']))
                    )->toOthers()
                );
            }

            $this->tryBroadcast(
                fn () => broadcast(new OrderStatusChanged($order, $oldStatus))->toOthers()
            );

            LogService::log($request, 'order.status_updated',
                "Commande #{$order->order_number} : {$oldStatus} → {$request->status}",
                'order', $order->id,
                ['status' => $oldStatus], ['status' => $request->status]);

            return response()->json(['success' => true, 'data' => $order]);

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function ensureCanCancel(Request $request): void
    {
        if (! in_array($request->user()->role, self::CANCEL_ROLES, true)) {
            abort(403, "Seuls le serveur, le gérant et l'administrateur peuvent annuler une commande.");
        }
    }

    private function cancelOrder(Request $request, Order $order)
    {
        $this->ensureCanCancel($request);

        $oldStatus = null;

        $error = DB::transaction(function () use ($order, &$oldStatus) {
            // Verrou : d'abord la commande, puis ses plats
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            $oldStatus = $locked->status;

            if (in_array($locked->status, ['paid', 'cancelled'], true)) {
                return 'Cette commande ne peut plus être annulée.';
            }

            $items = $locked->items()->lockForUpdate()->get(['id', 'kitchen_status']);
            $started = $items->contains(fn ($item) => $item->kitchen_status !== 'pending');

            if ($started || in_array($locked->status, ['ready', 'served'], true)) {
                return "Impossible d'annuler cette commande : la préparation d'au moins un plat a déjà commencé.";
            }

            $locked->update(['status' => 'cancelled']);
            $locked->table?->syncCurrentOrder();

            return null;
        });

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $order->refresh();

        $this->tryBroadcast(fn () => broadcast(new OrderStatusChanged($order, $oldStatus))->toOthers());
        $this->tryBroadcast(fn () => broadcast(new OrderCancelled($order)));

        LogService::log($request, 'order.cancelled',
            "Commande #{$order->order_number} annulée",
            'order', $order->id,
            ['status' => $oldStatus], ['status' => 'cancelled']);

        return response()->json(['success' => true, 'message' => 'Commande annulée', 'data' => $order]);
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
