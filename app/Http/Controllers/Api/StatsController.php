<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Table;
use App\Models\ZReport;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $stats = Cache::remember("stats_{$restaurantId}", 15, function () use ($restaurantId) {
            $today = now()->toDateString();

            $tables = Table::where('restaurant_id', $restaurantId)
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as occupied', ['occupied'])
                ->first();

            $activeOrders = Order::where('restaurant_id', $restaurantId)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->count();

            $todayStats = Order::where('restaurant_id', $restaurantId)
                ->whereDate('created_at', $today)
                ->selectRaw('COUNT(*) as today_orders, COALESCE(SUM(CASE WHEN status = ? THEN total ELSE 0 END), 0) as today_revenue', ['paid'])
                ->first();

            return [
                'tables' => (int) $tables->total,
                'occupiedTables' => (int) $tables->occupied,
                'activeOrders' => $activeOrders,
                'todayOrders' => (int) $todayStats->today_orders,
                'todayRevenue' => (int) $todayStats->today_revenue,
            ];
        });

        return response()->json($stats);
    }

    public function zReport(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'cashier'], true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent consulter le rapport Z."
        );

        $restaurantId = $request->user()->restaurant_id;
        $tz = 'Africa/Porto-Novo';

        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'), $tz)
            : Carbon::now($tz);

        $dateString = $date->toDateString();

        // 1) Si clôturé, renvoyer le snapshot figé
        $existing = ZReport::where('restaurant_id', $restaurantId)
            ->where('date', $dateString)
            ->first();

        if ($existing) {
            return response()->json(array_merge(
                $existing->snapshot,
                [
                    'closed' => true,
                    'sequence_code' => $existing->code,
                    'sequence_number' => $existing->sequence_number,
                    'closed_at' => $existing->closed_at?->toIso8601String(),
                    'closed_by' => $existing->closed_by,
                    'cash_counted' => $existing->cash_counted,
                    'cash_difference' => $existing->cash_difference,
                ]
            ));
        }

        // 2) Sinon, calcul en direct
        return response()->json(array_merge(
            $this->computeReport($restaurantId, $date),
            ['closed' => false]
        ));
    }

    /**
     * Calcul du rapport Z à une date donnée (jour local).
     * Extrait pour être partagé avec close().
     */
    private function computeReport(string $restaurantId, Carbon $date): array
    {
        $fromLocal = $date->copy()->startOfDay();
        $toLocal = $date->copy()->endOfDay();
        $fromUtc = $fromLocal->copy()->utc();
        $toUtc = $toLocal->copy()->utc();

        $paidOrders = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('completed_at', [$fromUtc, $toUtc])
            ->get(['id', 'total', 'subtotal', 'tax', 'service_charge', 'guest_count']);

        $totalOrders = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->count();

        $cancelledCount = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'cancelled')
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->count();

        $payments = Payment::whereHas(
            'order',
            fn ($q) => $q->where('restaurant_id', $restaurantId)
        )
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->get(['amount', 'method', 'operator']);

        $unclosedQuery = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'served')
            ->where('due_amount', '>', 0);
        $unclosedCount = (clone $unclosedQuery)->count();
        $unclosedAmount = (int) $unclosedQuery->sum('due_amount');

        $breakdown = [
            'cash' => ['key' => 'cash', 'label' => 'Espèces', 'amount' => 0, 'count' => 0],
            'mtn_momo' => ['key' => 'mtn_momo', 'label' => 'MTN MoMo', 'amount' => 0, 'count' => 0],
            'moov_money' => ['key' => 'moov_money', 'label' => 'Moov Money', 'amount' => 0, 'count' => 0],
            'celtiis_cash' => ['key' => 'celtiis_cash', 'label' => 'Celtiis Cash', 'amount' => 0, 'count' => 0],
        ];

        foreach ($payments as $payment) {
            if ($payment->method === 'cash') {
                $breakdown['cash']['amount'] += (int) $payment->amount;
                $breakdown['cash']['count']++;
            } elseif ($payment->method === 'mobile_money' && isset($breakdown[$payment->operator])) {
                $breakdown[$payment->operator]['amount'] += (int) $payment->amount;
                $breakdown[$payment->operator]['count']++;
            }
        }

        return [
            'date' => $date->toDateString(),
            'total_orders' => $totalOrders,
            'paid_orders' => $paidOrders->count(),
            'cancelled_orders' => $cancelledCount,
            'total_revenue' => (int) $paidOrders->sum('total'),
            'total_paid' => (int) $payments->sum('amount'),
            'payment_breakdown' => array_values($breakdown),
            'total_subtotal' => (int) $paidOrders->sum('subtotal'),
            'total_tax' => (int) $paidOrders->sum('tax'),
            'total_service_charge' => (int) $paidOrders->sum('service_charge'),
            'unclosed_orders' => $unclosedCount,
            'unclosed_amount' => $unclosedAmount,
            'guests_count' => (int) $paidOrders->sum('guest_count'),
        ];
    }

    public function close(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'cashier'], true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent clôturer le rapport Z."
        );

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cash_counted' => ['nullable', 'integer', 'min:0'],
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $tz = 'Africa/Porto-Novo';

        $date = ! empty($validated['date'])
            ? Carbon::parse($validated['date'], $tz)
            : Carbon::now($tz);

        $dateString = $date->toDateString();
        $dateCode = $date->format('Ymd');

        $zReport = DB::transaction(function () use ($request, $restaurantId, $date, $dateString, $dateCode, $validated) {
            // Verrou : pas de double clôture pour le même restaurant et la même date
            $existing = ZReport::where('restaurant_id', $restaurantId)
                ->where('date', $dateString)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Une clôture existe déjà pour cette date.'], 422)
                );
            }

            $sequence = (ZReport::where('restaurant_id', $restaurantId)
                ->where('date', $dateString)
                ->lockForUpdate()
                ->max('sequence_number') ?? 0) + 1;

            $snapshot = $this->computeReport($restaurantId, $date);
            $code = "Z-{$dateCode}-".str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            $cashCounted = $validated['cash_counted'] ?? null;
            $cashDifference = null;
            if ($cashCounted !== null) {
                $cashAmount = collect($snapshot['payment_breakdown'])
                    ->firstWhere('key', 'cash')['amount'] ?? 0;
                $cashDifference = $cashCounted - $cashAmount;
            }

            return ZReport::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'date' => $dateString,
                'sequence_number' => $sequence,
                'code' => $code,
                'snapshot' => $snapshot,
                'cash_counted' => $cashCounted,
                'cash_difference' => $cashDifference,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);
        });

        return response()->json([
            'id' => $zReport->id,
            'date' => $zReport->date instanceof Carbon ? $zReport->date->toDateString() : (string) $zReport->date,
            'sequence_number' => $zReport->sequence_number,
            'code' => $zReport->code,
            'snapshot' => $zReport->snapshot,
            'cash_counted' => $zReport->cash_counted,
            'cash_difference' => $zReport->cash_difference,
            'closed_by' => $zReport->closed_by,
            'closed_at' => $zReport->closed_at?->toIso8601String(),
        ], 201);
    }

    public function closedList(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'cashier'], true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent consulter l'historique des clôtures."
        );

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 30), 100);

        $query = ZReport::where('restaurant_id', $request->user()->restaurant_id)
            ->with(['closer:id,name,role'])
            ->orderByDesc('date')
            ->orderByDesc('sequence_number');

        if (! empty($validated['from'])) {
            $query->where('date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('date', '<=', $validated['to']);
        }

        $page = $query->paginate($perPage);

        $rows = $page->getCollection()->map(fn (ZReport $z) => [
            'id' => $z->id,
            'code' => $z->code,
            'date' => $z->date instanceof Carbon ? $z->date->toDateString() : (string) $z->date,
            'sequence_number' => $z->sequence_number,
            'cash_counted' => $z->cash_counted,
            'cash_difference' => $z->cash_difference,
            'closed_at' => $z->closed_at?->toIso8601String(),
            'closed_by' => $z->closer ? [
                'id' => $z->closer->id,
                'name' => $z->closer->name,
                'role' => $z->closer->role,
            ] : null,
        ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function closedShow(Request $request, string $id)
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'cashier'], true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent consulter une clôture Z."
        );

        $z = ZReport::where('restaurant_id', $request->user()->restaurant_id)
            ->where('id', $id)
            ->with(['closer:id,name,role'])
            ->firstOrFail();

        return response()->json([
            'id' => $z->id,
            'code' => $z->code,
            'date' => $z->date instanceof Carbon ? $z->date->toDateString() : (string) $z->date,
            'sequence_number' => $z->sequence_number,
            'snapshot' => $z->snapshot,
            'cash_counted' => $z->cash_counted,
            'cash_difference' => $z->cash_difference,
            'closed_at' => $z->closed_at?->toIso8601String(),
            'closed_by' => $z->closer ? [
                'id' => $z->closer->id,
                'name' => $z->closer->name,
                'role' => $z->closer->role,
            ] : null,
        ]);
    }
}
