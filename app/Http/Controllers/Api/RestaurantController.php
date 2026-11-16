<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fiche du restaurant de l'utilisateur connecté (en-tête des factures, etc.).
 */
class RestaurantController extends Controller
{
    private const PUBLIC_FIELDS = ['id', 'name', 'email', 'phone', 'address', 'currency'];

    public function show(Request $request)
    {
        return response()->json($request->user()->restaurant->only(self::PUBLIC_FIELDS));
    }

    public function update(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Accès réservé aux administrateurs');
        }

        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('restaurants', 'email')->ignore($restaurant->id)],
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:500',
        ]);

        $old = $restaurant->only(array_keys($data));
        $restaurant->update($data);

        LogService::log($request, 'restaurant.updated',
            'Informations du restaurant modifiées',
            'restaurant', $restaurant->id, $old, $restaurant->only(array_keys($data)));

        return response()->json($restaurant->only(self::PUBLIC_FIELDS));
    }
}
