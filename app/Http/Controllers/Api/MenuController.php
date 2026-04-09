<?php

// app/Http/Controllers/Api/MenuController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
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
            'name' => 'required|string',
            'price' => 'required|integer|min:0',
            'category_id' => 'nullable|exists:categories,id',
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

        return response()->json($item, 201);
    }

    public function update(Request $request, MenuItem $item)
    {
        $item->update($request->only(['name', 'price', 'category_id', 'description', 'preparation_time']));

        return response()->json($item);
    }

    public function destroy(MenuItem $item)
    {
        $item->delete();

        return response()->json(null, 204);
    }

    public function toggleAvailability(MenuItem $item)
    {
        $item->update(['is_available' => ! $item->is_available]);

        return response()->json($item);
    }
}
