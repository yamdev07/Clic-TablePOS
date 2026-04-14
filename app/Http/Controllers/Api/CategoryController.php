<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:100',
            'icon'          => 'nullable|string|max:10',
            'color'         => 'nullable|string|max:20',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $category = Category::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'slug'          => Str::slug($request->name) . '-' . Str::random(4),
            'icon'          => $request->icon,
            'color'         => $request->color,
            'display_order' => $request->display_order ?? 0,
            'is_active'     => true,
        ]);

        LogService::log($request, 'category.created',
            "Catégorie \"{$category->name}\" créée",
            'category', $category->id);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name'          => 'sometimes|required|string|max:100',
            'icon'          => 'nullable|string|max:10',
            'color'         => 'nullable|string|max:20',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $old = $category->only(['name']);
        $category->update($request->only(['name', 'icon', 'color', 'display_order', 'is_active']));

        if ($request->has('name')) {
            $category->update(['slug' => Str::slug($request->name) . '-' . Str::random(4)]);
        }

        LogService::log($request, 'category.updated',
            "Catégorie \"{$category->name}\" modifiée",
            'category', $category->id, $old, $category->only(['name']));

        return response()->json($category);
    }

    public function destroy(Category $category, Request $request)
    {
        // Empêcher la suppression si des plats y sont rattachés
        if ($category->menuItems()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une catégorie contenant des plats.',
            ], 422);
        }

        LogService::log($request, 'category.deleted',
            "Catégorie \"{$category->name}\" supprimée",
            'category', $category->id);

        $category->delete();

        return response()->json(null, 204);
    }
}
