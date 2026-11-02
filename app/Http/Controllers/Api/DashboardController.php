<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            in_array($user->role, ['admin', 'manager'], true),
            403,
            'Accès réservé aux administrateurs et gérants'
        );

        $request->validate(['period' => 'nullable|in:week,month']);
        $period = $request->input('period', 'week');

        $restaurantId = $user->restaurant_id;
        $timezone = $user->restaurant?->timezone ?: config('app.timezone');

        // Le « jour » est le jour calendaire dans le fuseau du restaurant
        $now = Carbon::now($timezone);
        $today = [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        $yesterdayStart = $now->copy()->subDay()->startOfDay();
        $yesterday = [$yesterdayStart, $yesterdayStart->copy()->endOfDay()];

        return response()->json([
            'period' => $period,
            'cards' => [
                'tables' => $this->tables($restaurantId),
                'revenue' => $this->revenue($restaurantId, $today, $yesterday),
                'orders' => $this->orders($restaurantId, $today),
                'covers' => $this->covers($restaurantId, $today, $yesterday),
            ],
        ]);
    }

    private function tables(string $restaurantId): array
    {
        $row = Table::where('restaurant_id', $restaurantId)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) as free_count")
            ->first();

        return [
            'active' => (int) $row->active,
            'free' => (int) $row->free_count,
            'total' => (int) $row->total,
        ];
    }

    private function revenue(string $restaurantId, array $today, array $yesterday): array
    {
        $todayTotal = $this->paymentsTotal($restaurantId, $today);
        $yesterdayTotal = $this->paymentsTotal($restaurantId, $yesterday);

        return [
            'today' => $todayTotal,
            'change_pct' => $this->changePct($todayTotal, $yesterdayTotal),
        ];
    }

    private function orders(string $restaurantId, array $today): array
    {
        $row = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', $this->toDbRange($today))
            ->where('status', '!=', 'cancelled')
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count, SUM(CASE WHEN status IN ('open', 'in_progress', 'ready', 'served') THEN 1 ELSE 0 END) as in_progress_count")
            ->first();

        return [
            'total' => (int) $row->total,
            'paid' => (int) $row->paid_count,
            'in_progress' => (int) $row->in_progress_count,
        ];
    }

    private function covers(string $restaurantId, array $today, array $yesterday): array
    {
        $todayTotal = $this->coversTotal($restaurantId, $today);
        $yesterdayTotal = $this->coversTotal($restaurantId, $yesterday);

        return [
            'today' => $todayTotal,
            'change_pct' => $this->changePct($todayTotal, $yesterdayTotal),
        ];
    }

    // ── Outils ──

    private function paymentsTotal(string $restaurantId, array $range): int
    {
        // Les paiements n'ont pas de restaurant_id : on passe par leur commande
        return (int) Payment::where('status', 'completed')
            ->whereBetween('created_at', $this->toDbRange($range))
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->sum('amount');
    }

    private function coversTotal(string $restaurantId, array $range): int
    {
        return (int) Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['paid', 'served'])
            ->whereBetween('created_at', $this->toDbRange($range))
            ->sum('guest_count');
    }

    private function changePct(int|float $current, int|float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /** Convertit une plage donnée dans le fuseau du restaurant vers celui de la base. */
    private function toDbRange(array $range): array
    {
        $dbTimezone = config('app.timezone');

        return [
            $range[0]->copy()->setTimezone($dbTimezone),
            $range[1]->copy()->setTimezone($dbTimezone),
        ];
    }
}
