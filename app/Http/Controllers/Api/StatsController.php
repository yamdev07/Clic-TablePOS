<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $fromLocal = $date->copy()->startOfDay();
        $toLocal = $date->copy()->endOfDay();
        $fromUtc = $fromLocal->copy()->utc();
        $toUtc = $toLocal->copy()->utc();

        // Commandes payées ce jour (référence = completed_at en UTC)
        $paidOrdersQuery = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('completed_at', [$fromUtc, $toUtc]);

        $paidOrders = $paidOrdersQuery->get([
            'id', 'total', 'subtotal', 'tax', 'service_charge', 'guest_count',
        ]);

        $totalOrders = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->count();

        $cancelledCount = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'cancelled')
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->count();

        // Encaissements (payments completed) de la journée
        $payments = Payment::whereHas(
            'order',
            fn ($q) => $q->where('restaurant_id', $restaurantId)
        )
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->get(['amount', 'method', 'operator']);

        // Commandes non clôturées (servies mais non payées)
        $unclosedQuery = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'served')
            ->where('due_amount', '>', 0);
        $unclosedCount = (clone $unclosedQuery)->count();
        $unclosedAmount = (int) $unclosedQuery->sum('due_amount');

        // Ventilation par opérateur
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

        return response()->json([
            'date' => $date->toDateString(),
            'total_orders' => $totalOrders,
            'paid_orders' => $paidOrders->count(),
            'cancelled_orders' => $cancelledCount,
            'total_revenue' => (int) $paidOrders->sum('total'),
            'total_paid' => (int) $payments->sum('amount'),
            'payment_breakdown' => array_values($breakdown),
            // enrichissements par la maquette
            'total_subtotal' => (int) $paidOrders->sum('subtotal'),
            'total_tax' => (int) $paidOrders->sum('tax'),
            'total_service_charge' => (int) $paidOrders->sum('service_charge'),
            'unclosed_orders' => $unclosedCount,
            'unclosed_amount' => $unclosedAmount,
            'guests_count' => (int) $paidOrders->sum('guest_count'),
        ]);
    }
}
