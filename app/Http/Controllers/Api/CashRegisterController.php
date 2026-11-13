<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CashRegisterController extends Controller
{
    private const READ_ROLES = ['admin', 'manager'];

    private const WRITE_ROLES = ['admin', 'manager'];

    private function ensureBelongsToRestaurant(Request $request, CashRegister $register): void
    {
        abort_unless(
            (string) $register->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    public function index(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à consulter les caisses."
        );

        $registers = CashRegister::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $registers]);
    }

    public function store(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à créer une caisse."
        );

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        LogService::log($request, 'cash_register.created',
            "Caisse \"{$register->name}\" créée",
            'cash_register', $register->id);

        return response()->json($register, 201);
    }

    public function update(Request $request, CashRegister $register)
    {
        $this->ensureBelongsToRestaurant($request, $register);

        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403
        );

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $register->update($validated);

        LogService::log($request, 'cash_register.updated',
            "Caisse \"{$register->name}\" modifiée",
            'cash_register', $register->id);

        return response()->json($register->fresh());
    }

    public function destroy(Request $request, CashRegister $register)
    {
        $this->ensureBelongsToRestaurant($request, $register);

        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403
        );

        if ($register->sessions()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une caisse ayant des sessions. Désactivez-la plutôt.',
            ], 422);
        }

        $name = $register->name;
        $register->delete();

        LogService::log($request, 'cash_register.deleted',
            "Caisse \"{$name}\" supprimée",
            'cash_register', null);

        return response()->json(null, 204);
    }
}
