<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function index()
    {
        $categories = Category::with(['menuItems' => function ($q) {
            $q->where('is_active', true)->orderBy('display_order');
        }])->orderBy('display_order')->get();

        return response()->json($categories);
    }

    public function show(MenuItem $item)
    {
        return response()->json($item->load('category'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string',
            'price'       => 'required|integer|min:0',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $item = MenuItem::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'category_id'   => $request->category_id,
            'name'          => $request->name,
            'price'         => $request->price,
            'is_available'  => true,
            'is_active'     => true,
        ]);

        LogService::log($request, 'menu.item_created',
            "Article \"{$item->name}\" ajouté au menu ({$item->price} FCFA)",
            'menu_item', $item->id);

        return response()->json($item, 201);
    }

    public function update(Request $request, MenuItem $item)
    {
        $old = $item->only(['name', 'price']);
        $item->update($request->only(['name', 'price', 'category_id', 'description', 'preparation_time']));

        LogService::log($request, 'menu.item_updated',
            "Article \"{$item->name}\" modifié",
            'menu_item', $item->id, $old, $item->only(['name', 'price']));

        return response()->json($item);
    }

    public function destroy(MenuItem $item, Request $request)
    {
        LogService::log($request, 'menu.item_deleted',
            "Article \"{$item->name}\" supprimé du menu",
            'menu_item', $item->id);

        $item->delete();

        return response()->json(null, 204);
    }

    public function toggleAvailability(MenuItem $item, Request $request)
    {
        $item->update(['is_available' => ! $item->is_available]);

        $state = $item->is_available ? 'disponible' : 'indisponible';
        LogService::log($request, 'menu.availability_changed',
            "Article \"{$item->name}\" marqué {$state}",
            'menu_item', $item->id);

        return response()->json($item);
    }
}
