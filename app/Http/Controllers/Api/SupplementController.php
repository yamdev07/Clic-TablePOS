<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSupplement;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupplementController extends Controller
{
    private const WRITE_ROLES = ['admin', 'manager'];

    private const READ_ROLES = ['admin', 'manager', 'cashier', 'waiter'];

    private function ensureBelongsToRestaurant(Request $request, RestaurantSupplement $supplement): void
    {
        abort_unless(
            (string) $supplement->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    public function index(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à consulter les suppléments."
        );

        $supplements = RestaurantSupplement::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $supplements]);
    }

    public function store(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Seuls l'administrateur et le gérant peuvent créer des suppléments."
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price_delta' => ['required', 'integer', 'min:0'],
        ]);

        $supplement = RestaurantSupplement::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'name' => $validated['name'],
            'price_delta' => $validated['price_delta'],
            'is_available' => true,
        ]);

        LogService::log($request, 'supplement.created',
            "Supplément \"{$supplement->name}\" créé ({$supplement->price_delta} FCFA)",
            'supplement', $supplement->id);

        return response()->json($supplement, 201);
    }

    public function destroy(Request $request, RestaurantSupplement $supplement)
    {
        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Seuls l'administrateur et le gérant peuvent supprimer des suppléments."
        );

        $this->ensureBelongsToRestaurant($request, $supplement);

        LogService::log($request, 'supplement.deleted',
            "Supplément \"{$supplement->name}\" supprimé",
            'supplement', $supplement->id);

        $supplement->delete();

        return response()->json(null, 204);
    }
}
