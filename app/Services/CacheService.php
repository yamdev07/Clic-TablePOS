<?php

// app/Services/CacheService.php

namespace App\Services;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    // Menu cache (1 heure)
    public function getMenu(string $restaurantId): array
    {
        $key = "menu:restaurant:{$restaurantId}";

        return Cache::remember($key, 3600, function () use ($restaurantId) {
            return Category::with('menuItems')
                ->where('restaurant_id', $restaurantId)
                ->orderBy('display_order')
                ->get()
                ->toArray();
        });
    }

    public function clearMenuCache(string $restaurantId): void
    {
        Cache::forget("menu:restaurant:{$restaurantId}");
    }

    // Tables cache (1 minute - temps réel)
    public function getTables(string $restaurantId): array
    {
        $key = "tables:restaurant:{$restaurantId}";

        return Cache::remember($key, 60, function () use ($restaurantId) {
            return Table::where('restaurant_id', $restaurantId)
                ->select('id', 'number', 'status', 'current_order_id', 'x_position', 'y_position')
                ->get()
                ->toArray();
        });
    }

    public function updateTableStatus(string $restaurantId, string $tableId, string $status): void
    {
        Cache::forget("tables:restaurant:{$restaurantId}");
        Cache::forget("table:{$tableId}");

        // Mettre à jour en base
        Table::where('id', $tableId)->update(['status' => $status]);
    }

    // Dashboard stats cache (5 minutes)
    public function getDashboardStats(string $restaurantId): array
    {
        $key = "dashboard:stats:{$restaurantId}";

        return Cache::remember($key, 300, function () use ($restaurantId) {
            $today = now()->toDateString();

            return [
                'today_orders' => Order::where('restaurant_id', $restaurantId)
                    ->whereDate('created_at', $today)
                    ->count(),
                'today_revenue' => Order::where('restaurant_id', $restaurantId)
                    ->whereDate('paid_at', $today)
                    ->sum('total'),
                'active_tables' => Table::where('restaurant_id', $restaurantId)
                    ->where('status', 'occupied')
                    ->count(),
                'pending_kitchen' => OrderItem::whereHas('order', function ($q) use ($restaurantId) {
                    $q->where('restaurant_id', $restaurantId);
                })->where('kitchen_status', 'pending')->count(),
            ];
        });
    }

    // Single table cache
    public function getTable(string $tableId): ?array
    {
        $key = "table:{$tableId}";

        return Cache::remember($key, 60, function () use ($tableId) {
            $table = Table::with('currentOrder')->find($tableId);

            return $table ? $table->toArray() : null;
        });
    }

    // Order cache (2 minutes)
    public function getOrder(string $orderId): ?array
    {
        $key = "order:{$orderId}";

        return Cache::remember($key, 120, function () use ($orderId) {
            $order = Order::with(['items', 'payments', 'table'])->find($orderId);

            return $order ? $order->toArray() : null;
        });
    }

    public function clearOrderCache(string $orderId): void
    {
        Cache::forget("order:{$orderId}");
    }
}
