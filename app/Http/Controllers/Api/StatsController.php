<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        // Cache 10 secondes — les stats changent souvent mais pas à chaque ms
        $stats = Cache::remember("stats_{$restaurantId}", 10, function () use ($restaurantId) {
            $tables = Table::where('restaurant_id', $restaurantId)
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as occupied', ['occupied'])
                ->first();

            $activeOrders = Order::where('restaurant_id', $restaurantId)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->count();

            return [
                'tables'         => (int) $tables->total,
                'occupiedTables' => (int) $tables->occupied,
                'activeOrders'   => $activeOrders,
            ];
        });

        return response()->json($stats);
    }

    public function zReport(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $date = $request->get('date', now()->toDateString());

        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $date)
            ->whereIn('status', ['paid', 'served', 'ready', 'in_progress', 'open', 'cancelled'])
            ->with('payments')
            ->get();

        $paidOrders = $orders->whereIn('status', ['paid']);

        $totalOrders   = $orders->count();
        $paidOrdersCount = $paidOrders->count();
        $cancelledCount = $orders->where('status', 'cancelled')->count();
        $totalRevenue  = $paidOrders->sum('total');
        $totalPaid     = $paidOrders->sum('paid_amount');

        // Breakdown by payment method
        $allPayments = Payment::whereHas('order', function ($q) use ($restaurantId, $date) {
            $q->where('restaurant_id', $restaurantId)
              ->whereDate('created_at', $date);
        })->get();

        $methodLabels = [
            'cash'         => 'Espèces',
            'card'         => 'Carte bancaire',
            'wave'         => 'Wave',
            'orange_money' => 'Orange Money',
        ];

        $breakdown = [];
        foreach ($allPayments as $payment) {
            $method = $payment->method;
            if (!isset($breakdown[$method])) {
                $breakdown[$method] = ['label' => $methodLabels[$method] ?? $method, 'amount' => 0, 'count' => 0];
            }
            $breakdown[$method]['amount'] += $payment->amount;
            $breakdown[$method]['count']++;
        }

        return response()->json([
            'date'              => $date,
            'total_orders'      => $totalOrders,
            'paid_orders'       => $paidOrdersCount,
            'cancelled_orders'  => $cancelledCount,
            'total_revenue'     => $totalRevenue,
            'total_paid'        => $totalPaid,
            'payment_breakdown' => array_values($breakdown),
        ]);
    }
}
