<?php

namespace App\Http\Controllers\Api;

use App\Events\KitchenOrderUpdated;
use App\Events\MenuItemOutOfStock;
use App\Events\OutOfStockReported;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    private function ensureMenuItemBelongsToRestaurant(Request $request, MenuItem $item): void
    {
        abort_unless(
            (string) $item->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }
        private function ensureCanDeclareRupture(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'kitchen'], true),
            403,
            "Seuls le cuisinier, le gérant et l'administrateur peuvent signaler une rupture."
        );
    }

    private function ensureCanRestock(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager'], true),
            403,
            "Seuls le gérant et l'administrateur peuvent remettre un plat en vente."
        );
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $request->validate([
            'orders_count_period' => 'nullable|in:30,all',
        ]);
        $period = $request->input('orders_count_period', '30');

        // 1 requête agrégée : nombre de commandes par plat sur la période
        $countQuery = OrderItem::query()
            ->selectRaw('menu_item_id, COUNT(DISTINCT order_id) as orders_count')
            ->whereHas('order', function ($q) use ($restaurantId, $period) {
                $q->where('restaurant_id', $restaurantId)
                    ->where('status', '!=', 'cancelled');

                if ($period === '30') {
                    $q->where('created_at', '>=', now()->subDays(30));
                }
            })
            ->groupBy('menu_item_id');

        $ordersCountByItem = $countQuery->pluck('orders_count', 'menu_item_id');

        $categories = Category::with(['menuItems' => function ($q) {
            $q->where('is_active', true)
                ->select(
                    'id', 'category_id', 'restaurant_id', 'name', 'description',
                    'price', 'preparation_time', 'is_available', 'display_order'
                )
                ->orderBy('display_order');
        }])
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->select('id', 'restaurant_id', 'name', 'slug', 'icon', 'color', 'display_order')
            ->orderBy('display_order')
            ->get();

        $categories->each(function (Category $category) use ($ordersCountByItem) {
            $category->menuItems->each(function (MenuItem $item) use ($ordersCountByItem) {
                $item->setAttribute('orders_count', (int) ($ordersCountByItem[$item->id] ?? 0));
            });
        });

        return response()->json($categories);
    }

    public function show(Request $request, MenuItem $item)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);

        $item->load(['category', 'options' => function ($q) {
            $q->orderBy('type')->orderBy('display_order')->orderBy('name');
        }]);

        return response()->json($item);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'price' => 'required|integer|min:0',
            'preparation_time' => 'nullable|integer|min:1|max:240',
            'category_id' => ['nullable', $this->ownCategory($request)],
        ]);

        $item = MenuItem::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'category_id' => $request->category_id,
            'name' => $request->name,
            'price' => $request->price,
            'preparation_time' => $request->preparation_time ?? 15,
            'is_available' => true,
            'is_active' => true,
        ]);

        LogService::log($request, 'menu.item_created',
            "Article \"{$item->name}\" ajouté au menu ({$item->price} FCFA)",
            'menu_item', $item->id);

        return response()->json($item, 201);
    }

    public function update(Request $request, MenuItem $item)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);

        $request->validate([
            'category_id' => ['sometimes', 'nullable', $this->ownCategory($request)],
            'preparation_time' => 'sometimes|integer|min:1|max:240',
            'options' => 'nullable|array',
            'options.*.type' => 'required|in:required_single,optional_single,optional_multi',
            'options.*.name' => 'required|string|max:100',
            'options.*.price_delta' => 'required|integer|min:0',
            'options.*.linked_menu_item_id' => 'nullable|uuid|exists:menu_items,id',
        ]);

        $old = $item->only(['name', 'price']);
        $item->update($request->only(['name', 'price', 'category_id', 'description', 'preparation_time']));

        if ($request->has('options')) {
            $this->syncOptions($request, $item, $request->input('options', []));
        }

        LogService::log($request, 'menu.item_updated',
            "Article \"{$item->name}\" modifié",
            'menu_item', $item->id, $old, $item->only(['name', 'price']));

        return response()->json($item->fresh()->load('options'));
    }

    /**
     * Remplace les options d'un plat.
     * Valide la règle métier : un plat dans les catégories "plats-principaux" ou "grillades"
     * doit avoir au moins une option required_single.
     */
    private function syncOptions(Request $request, MenuItem $item, array $options): void
    {
        $categorySlug = $item->category?->slug;
        $requiresPiment = in_array($categorySlug, ['plats-principaux', 'grillades'], true);

        if ($requiresPiment) {
            $hasRequired = collect($options)->contains(fn ($opt) => $opt['type'] === 'required_single');
            if (! $hasRequired) {
                abort(response()->json([
                    'message' => "Un plat dans la catégorie \"{$item->category->name}\" doit avoir au moins une option obligatoire (par exemple : Pimenté / Non pimenté).",
                ], 422));
            }
        }

        $item->options()->delete();

        foreach ($options as $index => $opt) {
            $item->options()->create([
                'id' => (string) Str::uuid(),
                'type' => $opt['type'],
                'name' => $opt['name'],
                'price_delta' => $opt['price_delta'] ?? 0,
                'linked_menu_item_id' => $opt['linked_menu_item_id'] ?? null,
                'display_order' => $index,
                'is_available' => true,
            ]);
        }
    }

    public function destroy(MenuItem $item, Request $request)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);

        $itemName = $item->name;
        $itemId = $item->id;

        $deleted = DB::transaction(function () use ($item) {
            $orderIds = $item->orderItems()
                ->pluck('order_id')
                ->unique()
                ->values();

            $orders = Order::whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get();

            if ($orders->contains(fn ($order) => $order->status !== 'open')) {
                return false;
            }

            foreach ($orders as $order) {
                $order->items()
                    ->where('menu_item_id', $item->id)
                    ->delete();

                $order->recalculate();
            }

            $item->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'Impossible de supprimer cet article : il est utilisé dans une commande déjà validée ou clôturée.',
            ], 422);
        }

        LogService::log(
            $request,
            'menu.item_deleted',
            "Article \"{$itemName}\" supprimé du menu",
            'menu_item',
            $itemId
        );

        return response()->json(null, 204);
    }

    /** La catégorie doit appartenir au restaurant de l'utilisateur. */
    private function ownCategory(Request $request)
    {
        return Rule::exists('categories', 'id')->where('restaurant_id', $request->user()->restaurant_id);
    }

    public function toggleAvailability(MenuItem $item, Request $request)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);
        $item->update(['is_available' => ! $item->is_available]);

        $state = $item->is_available ? 'disponible' : 'indisponible';
        LogService::log($request, 'menu.availability_changed',
            "Article \"{$item->name}\" marqué {$state}",
            'menu_item', $item->id);

        return response()->json($item);
    }

       public function markOutOfStock(Request $request, MenuItem $item)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);
        $this->ensureCanDeclareRupture($request);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $result = DB::transaction(function () use ($request, $item, $validated) {
            // Verrous dans le même ordre que la cuisine : commandes, plats, puis menu

            // 1. Commandes envoyées, pas encore lancées : le serveur doit les modifier ou les annuler
            $sentOrders = Order::where('restaurant_id', $item->restaurant_id)
                ->where('status', 'open')
                ->whereNotNull('confirmed_at')
                ->whereHas('items', fn ($q) => $q->where('menu_item_id', $item->id))
                ->with('table')
                ->lockForUpdate()
                ->get();

            // 2. Commandes déjà lancées : seuls les plats pas encore commencés sont barrés
            $launchedOrders = Order::where('restaurant_id', $item->restaurant_id)
                ->where('status', 'in_progress')
                ->whereHas('items', fn ($q) => $q->where('menu_item_id', $item->id)
                    ->where('kitchen_status', 'pending')
                    ->whereNull('out_of_stock_at'))
                ->lockForUpdate()
                ->get();

            $toStrike = [];
            foreach ($launchedOrders as $order) {
                $pending = $order->items()
                    ->where('menu_item_id', $item->id)
                    ->where('kitchen_status', 'pending')
                    ->whereNull('out_of_stock_at')
                    ->lockForUpdate()
                    ->get();

                if ($pending->isNotEmpty()) {
                    $toStrike[] = [$order, $pending];
                }
            }

            // Mise à jour atomique : si deux déclarations arrivent en même temps, une seule passe
            $updated = MenuItem::whereKey($item->id)
                ->whereNull('out_of_stock_at')
                ->update([
                    'is_available' => false,
                    'out_of_stock_at' => now(),
                    'out_of_stock_reason' => $validated['reason'],
                    'out_of_stock_by' => $request->user()->id,
                ]);

            if ($updated === 0) {
                return null;
            }

            $changedOrders = [];
            $struckItems = [];
            foreach ($toStrike as [$order, $pending]) {
                foreach ($pending as $orderItem) {
                    $orderItem->update([
                        'out_of_stock_at' => now(),
                        'kitchen_status' => 'served',
                    ]);
                    $struckItems[] = [$order, $orderItem];
                }
                $order->recalculate();
                $changedOrders[] = $order;
            }

            return [$sentOrders, $changedOrders, $struckItems];
        });

        if ($result === null) {
            return response()->json(['message' => 'Ce plat est déjà en rupture.'], 422);
        }

        [$sentOrders, $changedOrders, $struckItems] = $result;

        // Diffusions après la transaction : si elle échoue, personne n'est prévenu à tort
        $item = $item->fresh();

        foreach ($sentOrders as $order) {
            $this->tryBroadcast(fn () => broadcast(new MenuItemOutOfStock($item, $order))->toOthers());
        }

        foreach ($changedOrders as $order) {
            $this->tryBroadcast(fn () => broadcast(
                new KitchenOrderUpdated($order->fresh(['items', 'table']))
            )->toOthers());
        }

        foreach ($struckItems as [$order, $orderItem]) {
            $this->tryBroadcast(fn () => broadcast(
                new OutOfStockReported($order->fresh(['table']), $orderItem->fresh())
            )->toOthers());
        }

        LogService::log($request, 'menu.item_out_of_stock',
            "Article \"{$item->name}\" en rupture : {$validated['reason']}",
            'menu_item', $item->id);

        return response()->json($item->load('outOfStockBy:id,name'), 200);
    }

    public function markInStock(Request $request, MenuItem $item)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);
        $this->ensureCanRestock($request);

        if ($item->out_of_stock_at === null) {
            return response()->json(['message' => 'Ce plat n\'est pas en rupture.'], 422);
        }

        $item->update([
            'is_available' => true,
            'out_of_stock_at' => null,
            'out_of_stock_reason' => null,
            'out_of_stock_by' => null,
        ]);

        LogService::log($request, 'menu.item_in_stock',
            "Article \"{$item->name}\" de nouveau disponible",
            'menu_item', $item->id);

        return response()->json($item->fresh(), 200);
    }

    private function tryBroadcast(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Reverb indisponible
        }
    }
}
