<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatsControllerAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'capacity' => 4,
            'status' => 'occupied',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 5000,
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeOrder(string $status, ?string $createdAt = null, ?string $completedAt = null): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => $status,
            'total' => 5000,
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 5000,
            'total_price' => 5000,
            'options_total' => 0,
            'kitchen_status' => 'served',
        ]);

        if ($createdAt) {
            $order->created_at = $createdAt;
        }
        if ($completedAt) {
            $order->completed_at = $completedAt;
        }
        $order->saveQuietly();

        return $order;
    }

    // ------------------------------------------------------------------
    // 1. todayRevenue utilise completed_at (pas created_at)
    // ------------------------------------------------------------------

    public function test_today_revenue_uses_completed_at_not_created_at(): void
    {
        $this->actingAsRole('admin');

        // Commande créée il y a 2 jours, payée aujourd'hui
        $this->makeOrder('paid', now()->subDays(2)->toDateTimeString(), now()->toDateTimeString());

        // Commande créée aujourd'hui, mais pas encore payée
        $this->makeOrder('served', now()->toDateTimeString(), null);

        // Vider le cache (sinon le test précédent interfère)
        Cache::forget("stats_{$this->restaurant->id}");

        $data = $this->getJson('/api/stats')->assertOk()->json();

        // todayRevenue doit compter la commande payée aujourd'hui (5000)
        // et NON la commande créée aujourd'hui mais non payée
        $this->assertSame(5000, $data['todayRevenue']);
    }

    // ------------------------------------------------------------------
    // 2. todayOrders reste sur created_at
    // ------------------------------------------------------------------

    public function test_today_orders_uses_created_at(): void
    {
        $this->actingAsRole('admin');

        // Créée aujourd'hui (compte)
        $this->makeOrder('paid', now()->toDateTimeString(), now()->toDateTimeString());

        // Créée il y a 2 jours (ne compte pas)
        $this->makeOrder('paid', now()->subDays(2)->toDateTimeString(), now()->subDays(2)->toDateTimeString());

        Cache::forget("stats_{$this->restaurant->id}");

        $data = $this->getJson('/api/stats')->assertOk()->json();

        // todayOrders = 1 (la commande d'aujourd'hui)
        $this->assertSame(1, $data['todayOrders']);
    }

    // ------------------------------------------------------------------
    // 3. Le cache est invalidé quand une commande est payée
    // ------------------------------------------------------------------

    public function test_cache_is_invalidated_when_a_payment_is_made(): void
    {
        // 1. Charger les stats vides
        $this->actingAsRole('admin');
        Cache::forget("stats_{$this->restaurant->id}");
        $data1 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(0, $data1['todayRevenue']);

        // 2. Faire un paiement → doit invalider le cache
        $this->actingAsRole('cashier');
        $order = $this->makeOrder('served', now()->toDateTimeString(), null);

        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();

        // 3. Recharger les stats → doit refléter le paiement
        $this->actingAsRole('admin');
        $data2 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(5000, $data2['todayRevenue']);
    }
}
