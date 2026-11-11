<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    private function ensureMenuItemBelongsToRestaurant(
        Request $request,
        MenuItem $item
    ): void {
        abort_unless(
            (string) $item->restaurant_id ===
            (string) $request->user()->restaurant_id,
            404
        );
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $categories = Category::with(['menuItems' => function ($q) {
            $q->where('is_active', true)
                ->select('id', 'category_id', 'restaurant_id', 'name', 'description', 'price', 'preparation_time', 'is_available', 'display_order')
                ->orderBy('display_order');
        }])
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->select('id', 'restaurant_id', 'name', 'slug', 'icon', 'color', 'display_order')
            ->orderBy('display_order')
            ->get();

        return response()->json($categories);
    }

    public function show(Request $request, MenuItem $item)
    {
        $this->ensureMenuItemBelongsToRestaurant($request, $item);

        return response()->json($item->load('category'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'price' => 'required|integer|min:0',
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')
                    ->where('restaurant_id', $request->user()->restaurant_id),
            ],
        ]);

        $item = MenuItem::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'category_id' => $request->category_id,
            'name' => $request->name,
            'price' => $request->price,
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
            'category_id' => [
                'sometimes',
                'nullable',
                Rule::exists('categories', 'id')
                    ->where('restaurant_id', $request->user()->restaurant_id),
            ],
            'options' => 'nullable|array',
            'options.*.type' => 'required|in:required_single,optional_single,optional_multi',
            'options.*.name' => 'required|string|max:100',
            'options.*.price_delta' => 'required|integer|min:0',
            'options.*.linked_menu_item_id' => 'nullable|uuid|exists:menu_items,id',
        ]);

        $old = $item->only(['name', 'price']);
        $item->update($request->only(['name', 'price', 'category_id', 'description', 'preparation_time']));

        // Gestion des options si fournies
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
        // Règle métier : vérifier les catégories sensibles
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

        // Supprimer les options existantes
        $item->options()->delete();

        // Recréer
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

            // Récupérer les commandes qui utilisent cet article
            $orderIds = $item->orderItems()
                ->pluck('order_id')
                ->unique()
                ->values();

            // Verrouiller les commandes concernées et vérifier leurs statuts
            $orders = Order::whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get();

            // Refuser si au moins une commande n'est pas open
            if ($orders->contains(fn ($order) => $order->status !== 'open')) {
                return false;
            }

            // Retirer uniquement cet article de chaque commande open
            foreach ($orders as $order) {
                $order->items()
                    ->where('menu_item_id', $item->id)
                    ->delete();

                // Recalculer les montants sans supprimer les autres articles
                $order->recalculate();
            }

            // Supprimer l'article du menu
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
}
