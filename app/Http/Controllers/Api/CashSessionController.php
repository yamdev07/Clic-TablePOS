<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Payment;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashSessionController extends Controller
{
    private const WRITE_ROLES = ['admin', 'manager', 'cashier'];

    private const READ_ROLES = ['admin', 'manager', 'cashier'];

    private function ensureBelongsToRestaurant(Request $request, CashSession $session): void
    {
        abort_unless(
            (string) $session->cashRegister->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    public function open(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent ouvrir une caisse."
        );

        $validated = $request->validate([
            'cash_register_id' => 'required|uuid',
            'opening_balance' => 'required|integer|min:0',
        ]);

        // Vérifier que la caisse appartient au restaurant
        $register = CashRegister::where('id', $validated['cash_register_id'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->first();

        if (! $register) {
            abort(404, 'Caisse introuvable.');
        }

        // Vérifier qu'aucune session n'est ouverte pour cette caisse
        $existing = CashSession::where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'Une session est déjà ouverte pour cette caisse.',
            ], 422);
        }

        $session = CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $register->id,
            'status' => 'open',
            'opening_balance' => $validated['opening_balance'],
            'opened_by' => $request->user()->id,
            'opened_at' => now(),
        ]);

        LogService::log($request, 'cash_session.opened',
            "Session de caisse ouverte — {$register->name} ({$validated['opening_balance']} FCFA)",
            'cash_session', $session->id);

        return response()->json($session->load('cashRegister'), 201);
    }

    public function close(Request $request, CashSession $session)
    {
        $this->ensureBelongsToRestaurant($request, $session);

        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403
        );

        if ($session->status !== 'open') {
            return response()->json(['message' => 'Cette session est déjà fermée.'], 422);
        }

        $validated = $request->validate([
            'closing_balance' => 'required|integer|min:0',
        ]);

        $closed = DB::transaction(function () use ($session, $validated, $request) {
            $locked = CashSession::whereKey($session->id)->lockForUpdate()->first();

            // Total des paiements cash liés à cette session
            $cashCollected = Payment::where('cash_session_id', $locked->id)
                ->where('method', 'cash')
                ->where('status', 'completed')
                ->sum('amount');

            $expected = $locked->opening_balance + $cashCollected;
            $difference = $validated['closing_balance'] - $expected;

            $locked->update([
                'status' => 'closed',
                'expected_balance' => $expected,
                'closing_balance' => $validated['closing_balance'],
                'difference' => $difference,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);

            return $locked;
        });

        LogService::log($request, 'cash_session.closed',
            "Session de caisse fermée — écart : {$closed->difference} FCFA",
            'cash_session', $closed->id);

        return response()->json($closed->load(['cashRegister', 'opener', 'closer']));
    }

    public function show(Request $request, CashSession $session)
    {
        $this->ensureBelongsToRestaurant($request, $session);

        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403
        );

        return response()->json($session->load(['cashRegister', 'opener', 'closer']));
    }
}
