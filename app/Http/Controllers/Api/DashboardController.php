<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private function adminOrManagerOnly(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'manager'], true)) {
            abort(403, 'Accès réservé aux administrateurs et gérants');
        }
    }

    private const SERVICE_THRESHOLD_MINUTES = 15;

    private const BEST_SELLERS_DAYS = 30;

    public function index(Request $request)
    {
        $this->adminOrManagerOnly($request);
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
            'sales' => $this->sales($restaurantId, $period, $now),
            'service' => $this->service($restaurantId, $today),
            'best_sellers' => $this->bestSellers($restaurantId, $now),
            'activity' => $this->activity($restaurantId),
            'recent_orders' => $this->recentOrders($restaurantId),
        ]);
    }

    private function recentOrders(string $restaurantId): array
    {
        return Order::where('restaurant_id', $restaurantId)
            ->with(['table:id,number', 'user:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'order_number', 'table_id', 'user_id', 'status', 'total', 'created_at'])
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'table_number' => $order->table?->number,
                'server_name' => $order->user?->name,
                'total' => (int) $order->total,
                'status' => $order->status,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
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

    private function sales(string $restaurantId, string $period, Carbon $now): array
    {
        if ($period === 'week') {
            $start = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $previousStart = $start->copy()->subWeek();
            $daysInPeriod = 7;
            $elapsed = $now->isoWeekday();
            $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
            $comparableDays = $elapsed;
        } else {
            $start = $now->copy()->startOfMonth();
            $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
            $daysInPeriod = $now->daysInMonth;
            $elapsed = $now->day;
            $labels = array_map('strval', range(1, $daysInPeriod));
            $comparableDays = min($elapsed, $previousStart->daysInMonth);
        }

        // Une seule requête couvre la période précédente et la période en cours
        $payments = Payment::where('status', 'completed')
            ->whereBetween('created_at', $this->toDbRange([$previousStart, $now->copy()->endOfDay()]))
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->get(['amount', 'created_at']);

        $perDay = [];
        foreach ($payments as $payment) {
            $day = $payment->created_at->copy()->setTimezone($now->getTimezone())->toDateString();
            $perDay[$day] = ($perDay[$day] ?? 0) + $payment->amount;
        }

        $values = [];
        $total = 0;
        for ($i = 0; $i < $daysInPeriod; $i++) {
            if ($i >= $elapsed) {
                $values[] = null;   // jour à venir

                continue;
            }
            $amount = $perDay[$start->copy()->addDays($i)->toDateString()] ?? 0;
            $values[] = $amount;
            $total += $amount;
        }

        $previousTotal = 0;
        for ($i = 0; $i < $comparableDays; $i++) {
            $previousTotal += $perDay[$previousStart->copy()->addDays($i)->toDateString()] ?? 0;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'average' => (int) round($total / max($elapsed, 1)),
            'trend' => $this->trend($total, $previousTotal),
        ];
    }

    private function service(string $restaurantId, array $today): array
    {
        // Temps de préparation : du clic « Commencer » du cuisinier au clic « Prêt »
        $items = OrderItem::whereNotNull('started_at')
            ->whereNotNull('ready_at')
            ->whereBetween('ready_at', $this->toDbRange($today))
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->get(['id', 'order_id', 'started_at', 'ready_at']);

        if ($items->isEmpty()) {
            return ['avg_wait_minutes' => null, 'ready_under_15_pct' => null];
        }

        $waits = $items->map(fn ($item) => max(
            0,
            $item->ready_at->getTimestamp() - $item->started_at->getTimestamp()
        ) / 60);

        $fast = $waits->filter(fn ($minutes) => $minutes <= self::SERVICE_THRESHOLD_MINUTES)->count();

        return [
            'avg_wait_minutes' => (int) round($waits->avg()),
            'ready_under_15_pct' => round($fast / $waits->count() * 100, 1),
        ];
    }

    private function bestSellers(string $restaurantId, Carbon $now): array
    {
        // Les 30 derniers jours calendaires, aujourd'hui compris, dans le fuseau du restaurant
        $range = [
            $now->copy()->subDays(self::BEST_SELLERS_DAYS - 1)->startOfDay(),
            $now->copy()->endOfDay(),
        ];

        $items = OrderItem::whereHas('order', fn ($q) => $q
            ->where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', $this->toDbRange($range)))
            ->get(['menu_item_id', 'item_name', 'quantity', 'total_price']);

        $top = $items->groupBy('menu_item_id')
            ->map(fn ($group, $menuItemId) => [
                'menu_item_id' => $menuItemId,
                'name' => $group->first()->item_name,
                'quantity' => (int) $group->sum('quantity'),
                'revenue' => (int) $group->sum('total_price'),
            ])
            ->sortBy([['quantity', 'desc'], ['revenue', 'desc'], ['name', 'asc']])
            ->take(5)
            ->values();

        $images = MenuItem::where('restaurant_id', $restaurantId)
            ->whereIn('id', $top->pluck('menu_item_id'))
            ->pluck('image', 'id');

        return $top
            ->map(fn ($dish) => $dish + ['image' => $images[$dish['menu_item_id']] ?? null])
            ->all();
    }

    private function activity(string $restaurantId): array
    {
        return ActivityLog::where('restaurant_id', $restaurantId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'action', 'description', 'user_name', 'entity_type', 'created_at'])
            ->map(fn ($log) => $log->toFeedEntry())
            ->all();
    }

    private function trend(int $current, int $previous): ?string
    {
        if ($previous <= 0) {
            return null;
        }

        $change = ($current - $previous) / $previous * 100;

        return $change > 5 ? 'up' : ($change < -5 ? 'down' : 'stable');
    }
}
