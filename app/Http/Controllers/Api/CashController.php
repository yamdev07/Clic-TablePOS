<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CashController extends Controller
{
    private const ROLES = ['admin', 'manager', 'cashier'];

    private const TZ = 'Africa/Porto-Novo';

    public function summary(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent consulter le résumé de caisse."
        );

        $restaurantId = $request->user()->restaurant_id;

        $localNow = Carbon::now(self::TZ);
        $fromLocal = $localNow->copy()->startOfDay();
        $toLocal = $localNow->copy()->endOfDay();
        $fromUtc = $fromLocal->copy()->utc();
        $toUtc = $toLocal->copy()->utc();

        // CA du jour : commandes payées dont completed_at est dans la journée locale
        $revenueToday = (int) Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('completed_at', [$fromUtc, $toUtc])
            ->sum('total');

        // Encaissé : payments completed du jour
        $payments = Payment::whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->get(['amount', 'method', 'operator']);

        $collectedToday = (int) $payments->sum('amount');
        $paymentsCount = $payments->count();

        $byMethod = [
            'cash' => (int) $payments->where('method', 'cash')->sum('amount'),
            'mobile_money' => (int) $payments->where('method', 'mobile_money')->sum('amount'),
        ];

        $byOperator = [];
        foreach (['mtn_momo', 'moov_money', 'celtiis_cash'] as $op) {
            $byOperator[$op] = (int) $payments
                ->where('method', 'mobile_money')
                ->where('operator', $op)
                ->sum('amount');
        }

        // En attente : commandes servies, solde > 0
        $pendingAmount = (int) Order::where('restaurant_id', $restaurantId)
            ->where('status', 'served')
            ->where('due_amount', '>', 0)
            ->sum('due_amount');

        return response()->json([
            'date' => $localNow->toDateString(),
            'period' => [
                'from' => $fromLocal->toIso8601String(),
                'to' => $toLocal->toIso8601String(),
            ],
            'revenue_today' => $revenueToday,
            'collected_today' => $collectedToday,
            'pending_amount' => $pendingAmount,
            'collected_by_method' => $byMethod,
            'collected_by_operator' => $byOperator,
            'payments_count' => $paymentsCount,
        ]);
    }
}
