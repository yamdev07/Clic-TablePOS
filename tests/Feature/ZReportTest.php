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

class ZReportTest extends TestCase
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

    /** Commande servie avec un plat à 1000 → total 1230. */
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

    private function pay(Order $order, array $overrides = [])
    {
        return $this->postJson("/api/orders/{$order->id}/payments", array_merge([
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ], $overrides));
    }

    private function seedOperatorNumbers(): void
    {
        foreach (['mtn_momo' => '+22997000001', 'moov_money' => '+22996000002', 'celtiis_cash' => '+22995000003'] as $op => $phone) {
            PaymentNumber::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $this->restaurant->id,
                'operator' => $op,
                'phone_number' => $phone,
                'beneficiary_name' => 'Caisse',
                'is_active' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_admin_manager_and_cashier_can_see_the_z_report(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/stats/z-report')->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/stats/z-report')->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // 2. Isolation
    // ------------------------------------------------------------------

    public function test_z_report_is_isolated_across_restaurants(): void
    {
        // A : 1 commande payée
        $this->actingAsRole('cashier');
        $orderA = $this->servedOrder();
        $this->pay($orderA)->assertCreated();

        // B : 2 commandes payées
        $other = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $other);
        $this->pay($this->servedOrder($other))->assertCreated();
        $this->pay($this->servedOrder($other))->assertCreated();

        // Retour à A
        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        $this->assertSame(1, $data['paid_orders']);
        $this->assertSame(1230, $data['total_revenue']);
    }

    // ------------------------------------------------------------------
    // 3. Commandes payées / CA
    // ------------------------------------------------------------------

    public function test_z_report_counts_paid_orders_and_revenue_today(): void
    {
        $this->actingAsRole('cashier');

        $orderA = $this->servedOrder();
        $this->pay($orderA)->assertCreated();
        $orderB = $this->servedOrder();
        $this->pay($orderB)->assertCreated();

        // Commande servie mais non payée : ne doit pas être dans paid_orders ni revenue
        $this->servedOrder();

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        $this->assertSame(2, $data['paid_orders']);
        $this->assertSame(2460, $data['total_revenue']);
        $this->assertSame(2460, $data['total_paid']);
        $this->assertSame(0, $data['cancelled_orders']);
    }

    // ------------------------------------------------------------------
    // 4. Ventilation par moyen + opérateur
    // ------------------------------------------------------------------

    public function test_z_report_breaks_down_payment_methods_by_operator(): void
    {
        $this->seedOperatorNumbers();
        $this->actingAsRole('cashier');

        // 1 cash
        $this->pay($this->servedOrder())->assertCreated();
        // 1 mtn
        $this->pay($this->servedOrder(), [
            'method' => 'mobile_money', 'operator' => 'mtn_momo', 'reference' => 'M1',
        ])->assertCreated();
        // 1 moov
        $this->pay($this->servedOrder(), [
            'method' => 'mobile_money', 'operator' => 'moov_money', 'reference' => 'M2',
        ])->assertCreated();
        // 1 celtiis
        $this->pay($this->servedOrder(), [
            'method' => 'mobile_money', 'operator' => 'celtiis_cash', 'reference' => 'M3',
        ])->assertCreated();

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();
        $breakdown = collect($data['payment_breakdown'])->keyBy('key');

        $this->assertCount(4, $breakdown);
        $this->assertSame(1230, $breakdown['cash']['amount']);
        $this->assertSame(1, $breakdown['cash']['count']);
        $this->assertSame('Espèces', $breakdown['cash']['label']);

        $this->assertSame(1230, $breakdown['mtn_momo']['amount']);
        $this->assertSame(1, $breakdown['mtn_momo']['count']);
        $this->assertSame('MTN MoMo', $breakdown['mtn_momo']['label']);

        $this->assertSame(1230, $breakdown['moov_money']['amount']);
        $this->assertSame(1, $breakdown['moov_money']['count']);

        $this->assertSame(1230, $breakdown['celtiis_cash']['amount']);
        $this->assertSame(1, $breakdown['celtiis_cash']['count']);
    }

    // ------------------------------------------------------------------
    // 5. Fuseau horaire
    // ------------------------------------------------------------------

    public function test_z_report_uses_the_local_timezone_for_the_day(): void
    {
        $this->actingAsRole('cashier');

        // Commande payée aujourd'hui (created_at local = aujourd'hui)
        $orderToday = $this->servedOrder();
        $this->pay($orderToday)->assertCreated();

        // Commande payée "hier" selon completed_at mais Payment.created_at forcé à hier en UTC
        $orderYesterday = $this->servedOrder();
        $this->pay($orderYesterday)->assertCreated();
        $orderYesterday->update(['completed_at' => now()->subDay()]);
        Payment::where('order_id', $orderYesterday->id)->update([
            'created_at' => now()->subDay(),
        ]);

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        $this->assertSame(1, $data['paid_orders']);
        $this->assertSame(1230, $data['total_revenue']);
        // 1 seul Payment aujourd'hui
        $totalCount = collect($data['payment_breakdown'])->sum('count');
        $this->assertSame(1, $totalCount);
    }

    // ------------------------------------------------------------------
    // 6. Filtre date
    // ------------------------------------------------------------------

    public function test_z_report_date_filter_changes_the_scope(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        $this->pay($order)->assertCreated();

        // Rapport d'hier : rien
        $yesterday = now()->subDay()->toDateString();
        $data = $this->getJson("/api/stats/z-report?date={$yesterday}")->assertOk()->json();
        $this->assertSame(0, $data['paid_orders']);
        $this->assertSame(0, $data['total_revenue']);

        // Rapport d'aujourd'hui : 1
        $today = now()->toDateString();
        $data = $this->getJson("/api/stats/z-report?date={$today}")->assertOk()->json();
        $this->assertSame(1, $data['paid_orders']);
        $this->assertSame(1230, $data['total_revenue']);
    }

    // ------------------------------------------------------------------
    // 7. Bonus : commandes annulées / non payées exclues du CA
    // ------------------------------------------------------------------

    public function test_z_report_ignores_cancelled_and_unpaid_orders_in_revenue(): void
    {
        $this->actingAsRole('cashier');

        // 1 payée
        $this->pay($this->servedOrder())->assertCreated();
        // 1 annulée
        $cancelled = $this->servedOrder();
        $cancelled->update(['status' => 'cancelled']);
        // 1 en cours
        $this->servedOrder(null, 'in_progress');

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        $this->assertSame(1, $data['paid_orders']);
        $this->assertSame(1, $data['cancelled_orders']);
        $this->assertSame(1230, $data['total_revenue']);
    }
}
