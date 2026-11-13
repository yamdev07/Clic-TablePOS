<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
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

            $expected = $locked->expectedBalance();
            $difference = $validated['closing_balance'] - $expected;

            $locked->update([
                'status' => 'closed',
                'expected_balance' => $expected,
                'closing_balance' => $validated['closing_balance'],
                'difference' => $difference,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);

            // Générer et figer le snapshot du rapport
            $locked->update([
                'report_snapshot' => $this->buildReport($locked),
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

    public function addMovement(Request $request, CashSession $session)
    {
        $this->ensureBelongsToRestaurant($request, $session);

        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent ajouter un mouvement."
        );

        if ($session->status !== 'open') {
            return response()->json([
                'message' => 'Cette session est fermée : aucun mouvement possible.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => 'required|in:in,out',
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ]);

        // Pour un retrait, vérifier qu'il ne dépasse pas le solde théorique
        if ($validated['type'] === 'out') {
            $expected = $session->expectedBalance();
            if ($validated['amount'] > $expected) {
                return response()->json([
                    'message' => "Retrait impossible : montant supérieur au solde théorique ({$expected} FCFA).",
                    'expected_balance' => $expected,
                ], 422);
            }
        }

        $movement = CashMovement::create([
            'id' => (string) Str::uuid(),
            'cash_session_id' => $session->id,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()->id,
        ]);

        LogService::log($request, 'cash_session.movement',
            "Mouvement {$validated['type']} de {$validated['amount']} FCFA — {$validated['reason']}",
            'cash_session', $session->id);

        return response()->json($movement, 201);
    }

    public function listMovements(Request $request, CashSession $session)
    {
        $this->ensureBelongsToRestaurant($request, $session);

        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403
        );

        $movements = $session->movements()
            ->with('creator:id,name')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $movements]);
    }

    public function report(Request $request, CashSession $session)
    {
        $this->ensureBelongsToRestaurant($request, $session);

        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à consulter le rapport de caisse."
        );

        // Si la session est fermée et que le snapshot existe → renvoyer tel quel
        if ($session->status === 'closed' && $session->report_snapshot !== null) {
            return response()->json($session->report_snapshot);
        }

        // Sinon, calculer à la volée (session ouverte ou snapshot manquant)
        return response()->json($this->buildReport($session));
    }

    /**
     * Construit le rapport de caisse complet (figé à la fermeture).
     */
    private function buildReport(CashSession $session): array
    {
        $session->loadMissing(['cashRegister', 'opener:id,name', 'closer:id,name', 'movements.creator:id,name']);

        $cashCollected = (int) $session->payments()
            ->where('method', 'cash')
            ->where('status', 'completed')
            ->sum('amount');

        $paymentsCount = $session->payments()
            ->where('method', 'cash')
            ->where('status', 'completed')
            ->count();

        $movementsIn = (int) $session->movements()->where('type', 'in')->sum('amount');
        $movementsOut = (int) $session->movements()->where('type', 'out')->sum('amount');

        $expected = $session->opening_balance + $cashCollected + $movementsIn - $movementsOut;

        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'cash_register' => $session->cashRegister ? [
                'id' => $session->cashRegister->id,
                'name' => $session->cashRegister->name,
            ] : null,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'opened_by' => $session->opener ? [
                'id' => $session->opener->id,
                'name' => $session->opener->name,
            ] : null,
            'closed_by' => $session->closer ? [
                'id' => $session->closer->id,
                'name' => $session->closer->name,
            ] : null,
            'opening_balance' => (int) $session->opening_balance,
            'cash_collected' => $cashCollected,
            'movements_in' => $movementsIn,
            'movements_out' => $movementsOut,
            'expected_balance' => $expected,
            'closing_balance' => $session->closing_balance !== null ? (int) $session->closing_balance : null,
            'difference' => $session->difference !== null ? (int) $session->difference : null,
            'payments_count' => $paymentsCount,
            'movements' => $session->movements->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => (int) $m->amount,
                'reason' => $m->reason,
                'at' => $m->created_at?->toIso8601String(),
                'by' => $m->creator?->name,
            ])->values()->all(),
        ];
    }
}
