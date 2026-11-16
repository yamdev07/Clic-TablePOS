<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Http\Request;

/**
 * Administration de la plateforme SaaS (super_admin uniquement).
 * L'activation des abonnements est manuelle : le super admin passe
 * le restaurant en « active » après réception du paiement.
 */
class PlatformController extends Controller
{
    public function stats()
    {
        $byStatus = Restaurant::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'restaurants' => $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'trial' => (int) ($byStatus['trial'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'trials_expired' => Restaurant::where('status', 'trial')->where('trial_ends_at', '<', now())->count(),
            'orders_last_30d' => Order::where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:active,trial,suspended',
            'search' => 'nullable|string|max:100',
        ]);

        $restaurants = Restaurant::query()
            ->select('id', 'name', 'slug', 'email', 'phone', 'status', 'trial_ends_at', 'created_at')
            ->withCount(['users', 'tables', 'orders'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, function ($q, $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($restaurants);
    }

    public function show(Restaurant $restaurant)
    {
        $restaurant->loadCount(['users', 'tables', 'orders', 'menuItems']);
        $restaurant->load(['users' => fn ($q) => $q->select('id', 'restaurant_id', 'name', 'email', 'role', 'is_active', 'last_login_at')]);
        $restaurant->setAttribute(
            'last_order_at',
            $restaurant->orders()->latest()->value('created_at')
        );

        return response()->json($restaurant);
    }

    public function update(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:active,trial,suspended',
            'trial_ends_at' => 'sometimes|nullable|date',
        ]);

        $restaurant->update($data);

        return response()->json($restaurant->only(['id', 'name', 'status', 'trial_ends_at']));
    }
}
