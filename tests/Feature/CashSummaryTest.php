<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentNumber;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);
    }

    private function actingAsRole(string $role, ?Restaurant $restaurant = null): User
    {
        $user = User::factory()->create([
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Commande servie, 1 plat à 1000 → total 1230. */
    private function servedOrder(?Restaurant $restaurant = null, ?string $status = 'served'): Order
    {
        $restaurant ??= $this->restaurant;
        $table = $restaurant->is($this->restaurant)
            ? $this->table
            : Table::factory()->create(['restaurant_id' => $restaurant->id, 'number' => '9']);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'status' => $status,
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'served',
        ]);

        $order->recalculate();

        return $order->fresh();
    }

    private function pay(Order $order, ?int $amount = null, array $overrides = []): Payment
    {
        $payload = array_merge([
            'amount' => $amount ?? $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ], $overrides);

        $this->postJson("/api/orders/{$order->id}/payments", $payload)->assertCreated();

        return $order->payments()->latest()->first();
    }

    public function test_only_cashier_manager_and_admin_can_see_the_cash_summary(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/cash/summary')->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/cash/summary')->assertOk();
        }
    }

    public function test_cash_summary_is_isolated_across_restaurants(): void
    {
        // Paiement dans le restaurant A
        $this->actingAsRole('cashier');
        $orderA = $this->servedOrder();
        $this->pay($orderA);

        // Paiement dans un autre restaurant
        $other = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $other);
        $otherOrder = $this->servedOrder($other);
        $this->pay($otherOrder);

        // Le caissier A ne doit voir que le paiement A
        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/cash/summary')->assertOk()->json();

        $this->assertSame(1230, $data['revenue_today']);
        $this->assertSame(1230, $data['collected_today']);
    }

    public function test_revenue_today_counts_only_orders_paid_today(): void
    {
        $this->actingAsRole('cashier');

        // Commande payée aujourd'hui
        $orderToday = $this->servedOrder();
        $this->pay($orderToday);

        // Commande payée hier (on force completed_at dans le passé)
        $orderYesterday = $this->servedOrder();
        $this->pay($orderYesterday);
        $orderYesterday->update([
            'completed_at' => now()->subDay(),
        ]);

        $data = $this->getJson('/api/cash/summary')->assertOk()->json();

        $this->assertSame(1230, $data['revenue_today']);
        // Encaissé reste 2× car le Payment de hier a created_at d'aujourd'hui
        $this->assertSame(2460, $data['collected_today']);
    }

    public function test_served_yesterday_paid_today_counts_in_revenue_today(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        // La commande a été créée/servie hier, mais payée aujourd'hui
        $order->update([
            'created_at' => now()->subDay(),
        ]);
        $this->pay($order);
        $this->assertNotNull($order->fresh()->completed_at);

        $data = $this->getJson('/api/cash/summary')->assertOk()->json();
        $this->assertSame(1230, $data['revenue_today']);
        $this->assertSame(1230, $data['collected_today']);
    }

    public function test_collected_today_counts_payments_not_orders(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder(); // total 1230

        // Deux paiements partiels sur la même commande : 500 puis 730
        $this->pay($order, 500);
        $this->pay($order->fresh(), 730);

        $this->assertSame('paid', $order->fresh()->status);

        $data = $this->getJson('/api/cash/summary')->assertOk()->json();
        $this->assertSame(1230, $data['revenue_today']);
        $this->assertSame(1230, $data['collected_today']);
        $this->assertSame(2, $data['payments_count']);
    }

    public function test_collected_by_method_and_operator_split_correctly(): void
    {
        PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'mtn_momo',
            'phone_number' => '+22997000001',
            'beneficiary_name' => 'Caisse A',
            'is_active' => true,
        ]);

        $this->actingAsRole('cashier');

        // Cash
        $order1 = $this->servedOrder();
        $this->pay($order1, null, ['method' => 'cash']);

        // Mobile Money mtn_momo
        $order2 = $this->servedOrder();
        $this->pay($order2, null, [
            'method' => 'mobile_money', 'operator' => 'mtn_momo', 'reference' => 'MOMO-1',
        ]);

        $data = $this->getJson('/api/cash/summary')->assertOk()->json();

        $this->assertSame(1230, $data['collected_by_method']['cash']);
        $this->assertSame(1230, $data['collected_by_method']['mobile_money']);
        $this->assertSame(1230, $data['collected_by_operator']['mtn_momo']);
        $this->assertSame(0, $data['collected_by_operator']['moov_money']);
        $this->assertSame(0, $data['collected_by_operator']['celtiis_cash']);
    }

    public function test_pending_amount_includes_served_orders_with_pending_collection_request(): void
    {
        $this->actingAsRole('waiter');

        // Commande dans une demande pending
        $order = $this->servedOrder();
        $this->postJson('/api/collection-requests', [
            'order_ids' => [$order->id], 'method' => 'cash',
        ])->assertCreated();

        // Commande servie non payée, pas dans une demande
        $this->actingAsRole('waiter');
        $free = $this->servedOrder();

        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/cash/summary')->assertOk()->json();

        $this->assertSame(2460, $data['pending_amount']); // 1230 + 1230
        $this->assertSame(0, $data['collected_today']);
    }

    public function test_cash_summary_handles_empty_categories(): void
    {
        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/cash/summary')->assertOk()->json();

        $this->assertSame(0, $data['revenue_today']);
        $this->assertSame(0, $data['collected_today']);
        $this->assertSame(0, $data['pending_amount']);
        $this->assertSame(['cash' => 0, 'mobile_money' => 0], $data['collected_by_method']);
        $this->assertSame([
            'mtn_momo' => 0,
            'moov_money' => 0,
            'celtiis_cash' => 0,
        ], $data['collected_by_operator']);
        $this->assertSame(0, $data['payments_count']);
    }
}
