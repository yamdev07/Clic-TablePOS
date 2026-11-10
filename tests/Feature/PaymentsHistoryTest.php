<?php

namespace Tests\Feature;

use App\Models\CollectionRequest;
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

class PaymentsHistoryTest extends TestCase
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

    private function servedOrder(?Restaurant $restaurant = null): Order
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
            'status' => 'served',
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

    private function payDirect(Order $order, array $overrides = [])
    {
        return $this->postJson("/api/orders/{$order->id}/payments", array_merge([
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ], $overrides));
    }

    private function validateCollectionRequest(array $orderIds, array $overrides = []): CollectionRequest
    {
        // Créer la demande en tant que serveur
        $this->actingAsRole('waiter');
        $id = $this->postJson('/api/collection-requests', array_merge([
            'order_ids' => $orderIds, 'method' => 'cash',
        ], $overrides))->assertCreated()->json('id');

        // Valider en tant que caissier
        $this->actingAsRole('cashier');
        $this->postJson("/api/collection-requests/{$id}/validate", [
            'receipt_confirmed' => true,
        ])->assertOk();

        return CollectionRequest::findOrFail($id);
    }

    public function test_only_cashier_manager_and_admin_can_see_the_history(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/payments')->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/payments')->assertOk();
        }
    }

    public function test_history_is_isolated_across_restaurants(): void
    {
        // Paiement A
        $this->actingAsRole('cashier');
        $orderA = $this->servedOrder();
        $this->payDirect($orderA)->assertCreated();

        // Paiement B
        $other = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $other);
        $orderB = $this->servedOrder($other);
        $this->payDirect($orderB)->assertCreated();

        // Historique A ne voit que A
        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/payments')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($orderA->id, $data[0]['orders'][0]['order_id']);
    }

    public function test_history_groups_payments_by_payment_group_id(): void
    {
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();

        $this->validateCollectionRequest([$orderA->id, $orderB->id]);

        $data = $this->getJson('/api/payments')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['is_group']);
        $this->assertSame(2, $data[0]['orders_count']);
        $this->assertSame(2460, $data[0]['total_amount']);
        $this->assertNotNull($data[0]['group_id']);
    }

    public function test_history_keeps_individual_payments_separate(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        $this->payDirect($order)->assertCreated();

        $data = $this->getJson('/api/payments')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertFalse($data[0]['is_group']);
        $this->assertNull($data[0]['group_id']);
        $this->assertSame(1, $data[0]['orders_count']);
        $this->assertSame(1230, $data[0]['total_amount']);
    }

    public function test_history_filters_by_method_and_operator(): void
    {
        PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'mtn_momo',
            'phone_number' => '+22997000001',
            'beneficiary_name' => 'Caisse A',
            'is_active' => true,
        ]);
        PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'moov_money',
            'phone_number' => '+22996000002',
            'beneficiary_name' => 'Caisse B',
            'is_active' => true,
        ]);

        $this->actingAsRole('cashier');

        // 1 cash
        $this->payDirect($this->servedOrder())->assertCreated();
        // 1 mtn
        $o2 = $this->servedOrder();
        $this->payDirect($o2, ['method' => 'mobile_money', 'operator' => 'mtn_momo', 'reference' => 'M1'])->assertCreated();
        // 1 moov
        $o3 = $this->servedOrder();
        $this->payDirect($o3, ['method' => 'mobile_money', 'operator' => 'moov_money', 'reference' => 'M2'])->assertCreated();

        // filtre operator=mtn_momo → 1 résultat, method mobile_money
        $data = $this->getJson('/api/payments?operator=mtn_momo')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('mobile_money', $data[0]['method']);
        $this->assertSame('mtn_momo', $data[0]['operator']);

        // filtre method=cash → 1 résultat
        $data = $this->getJson('/api/payments?method=cash')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('cash', $data[0]['method']);

        // filtre method=mobile_money → 2 résultats
        $data = $this->getJson('/api/payments?method=mobile_money')->assertOk()->json('data');
        $this->assertCount(2, $data);
    }

    public function test_history_refuses_operator_with_method_cash(): void
    {
        $this->actingAsRole('cashier');
        $this->getJson('/api/payments?method=cash&operator=mtn_momo')
            ->assertStatus(422)
            ->assertJsonValidationErrors('operator');
    }

    public function test_history_filters_by_date_range(): void
    {
        $this->actingAsRole('cashier');
        $orderOld = $this->servedOrder();
        $this->payDirect($orderOld)->assertCreated();
        // On recule created_at de 3 jours
        Payment::where('order_id', $orderOld->id)
            ->update(['created_at' => now()->subDays(3)]);

        $orderNew = $this->servedOrder();
        $this->payDirect($orderNew)->assertCreated();

        $from = now()->subDay()->toDateString();
        $data = $this->getJson("/api/payments?from={$from}")->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($orderNew->id, $data[0]['orders'][0]['order_id']);
    }

    public function test_history_pagination_respects_per_page_and_cap(): void
    {
        $this->actingAsRole('cashier');
        for ($i = 0; $i < 3; $i++) {
            $this->payDirect($this->servedOrder())->assertCreated();
        }

        // per_page=2 → 2 items
        $data = $this->getJson('/api/payments?per_page=2')->assertOk()->json();
        $this->assertCount(2, $data['data']);
        $this->assertSame(2, $data['meta']['per_page']);
        $this->assertSame(3, $data['meta']['total']);

        // per_page=500 → capé à 100
        $data = $this->getJson('/api/payments?per_page=500')->assertOk()->json();
        $this->assertSame(100, $data['meta']['per_page']);
    }
}
