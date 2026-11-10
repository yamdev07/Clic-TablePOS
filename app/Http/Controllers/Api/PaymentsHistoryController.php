<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentsHistoryController extends Controller
{
    private const ROLES = ['admin', 'manager', 'cashier'];

    private const OPERATORS = ['mtn_momo', 'moov_money', 'celtiis_cash'];

    private const MAX_PER_PAGE = 100;

    public function index(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent consulter l'historique."
        );

        $validated = $request->validate([
            'method' => ['nullable', Rule::in(['cash', 'mobile_money'])],
            'operator' => ['nullable', Rule::in(self::OPERATORS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['operator']) && ($validated['method'] ?? null) === 'cash') {
            throw ValidationException::withMessages([
                'operator' => 'Un opérateur Mobile Money ne peut pas être filtré avec method=cash.',
            ]);
        }

        $method = $validated['method'] ?? null;
        if (isset($validated['operator']) && $method === null) {
            $method = 'mobile_money';
        }

        $perPage = min((int) ($validated['per_page'] ?? 30), self::MAX_PER_PAGE);

        $restaurantId = $request->user()->restaurant_id;

        $query = Payment::query()
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->where('status', 'completed')
            ->with(['order:id,order_number,restaurant_id', 'user:id,name']);

        if ($method !== null) {
            $query->where('method', $method);
        }
        if (isset($validated['operator'])) {
            $query->where('operator', $validated['operator']);
        }
        if (isset($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }

        $payments = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        // Regroupement par payment_group_id (null = individuels)
        $grouped = $payments->getCollection()->groupBy('payment_group_id');

        $rows = collect();
        foreach ($grouped as $groupId => $groupPayments) {
            if ($groupId === '' || $groupId === null) {
                // paiements individuels : un par un
                foreach ($groupPayments as $p) {
                    $rows->push($this->serializeSingle($p));
                }
            } else {
                $rows->push($this->serializeGroup($groupId, $groupPayments));
            }
        }

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    private function serializeSingle(Payment $p): array
    {
        return [
            'id' => $p->id,
            'group_id' => null,
            'is_group' => false,
            'total_amount' => (int) $p->amount,
            'orders_count' => 1,
            'method' => $p->method,
            'operator' => $p->operator,
            'reference' => $p->reference,
            'cashier' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'orders' => [[
                'order_id' => $p->order_id,
                'order_number' => $p->order?->order_number,
                'amount' => (int) $p->amount,
            ]],
        ];
    }

    private function serializeGroup(string $groupId, $groupPayments): array
    {
        $first = $groupPayments->first();

        return [
            'id' => $first->id, // id stable pour la liste
            'group_id' => $groupId,
            'is_group' => true,
            'total_amount' => (int) $groupPayments->sum('amount'),
            'orders_count' => $groupPayments->count(),
            'method' => $first->method,
            'operator' => $first->operator,
            'reference' => $first->reference,
            'cashier' => $first->user ? ['id' => $first->user->id, 'name' => $first->user->name] : null,
            'created_at' => $first->created_at?->toIso8601String(),
            'orders' => $groupPayments->map(fn ($p) => [
                'order_id' => $p->order_id,
                'order_number' => $p->order?->order_number,
                'amount' => (int) $p->amount,
            ])->values()->all(),
        ];
    }
}
