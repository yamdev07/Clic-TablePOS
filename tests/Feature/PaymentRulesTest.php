<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentRulesTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
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

    /** Commande servie de 12300 : 2 plats à 5000, plus taxes et service. */
    private function servedOrder(?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;
        $table = $restaurant->is($this->restaurant)
            ? $this->table
            : Table::factory()->create(['restaurant_id' => $restaurant->id, 'number' => '1']);
        $dish = MenuItem::factory()->create(['restaurant_id' => $restaurant->id, 'price' => 5000]);

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
            'menu_item_id' => $dish->id,
            'item_name' => $dish->name,
            'quantity' => 2,
            'unit_price' => 5000,
            'total_price' => 10000,
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

    public function test_only_cashier_manager_and_admin_can_collect(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $order = $this->servedOrder();

            $this->pay($order)->assertForbidden();

            $this->assertSame(0, $order->payments()->count(), "rôle {$role}");
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->servedOrder();

            $this->pay($order)->assertCreated();

            $this->assertSame('paid', $order->fresh()->status, "rôle {$role}");
        }
    }

    public function test_a_collection_needs_the_cashier_confirmation_even_for_cash(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        $url = "/api/orders/{$order->id}/payments";
        $due = $order->due_amount;

        $this->postJson($url, ['amount' => $due, 'method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt_confirmed');

        $this->postJson($url, ['amount' => $due, 'method' => 'cash', 'receipt_confirmed' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt_confirmed');

        $this->assertSame(0, $order->payments()->count());

        $this->pay($order)->assertCreated();

        $payment = $order->payments()->first();
        $this->assertSame($cashier->id, $payment->user_id);
        $this->assertNotNull($payment->transaction_data['receipt_confirmed_at'] ?? null);
    }

    public function test_mobile_money_needs_an_operator_and_a_reference(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        $base = ['method' => 'mobile_money', 'operator' => 'mtn_momo', 'reference' => 'MOM-20261108-001'];

        $invalid = [
            'sans opérateur' => ['operator' => null],
            'opérateur inconnu' => ['operator' => 'orange_money'],
            'sans référence' => ['reference' => null],
            'référence vide' => ['reference' => ''],
        ];

        foreach ($invalid as $label => $override) {
            $this->pay($order, array_merge($base, $override))->assertStatus(422);

            $this->assertSame(0, $order->payments()->count(), $label);
        }

        $this->pay($order, $base)->assertCreated();

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'mobile_money',
            'operator' => 'mtn_momo',
            'reference' => 'MOM-20261108-001',
            'status' => 'completed',
        ]);
    }

    public function test_only_cash_and_mobile_money_are_accepted(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        foreach (['card', 'wave', 'orange_money', 'free_money', 'cheque'] as $method) {
            $this->pay($order, ['method' => $method, 'reference' => 'X'])->assertStatus(422);
        }

        $this->assertSame(0, $order->payments()->count());
    }

    public function test_a_mobile_money_reference_cannot_be_used_twice_for_the_same_operator_in_a_restaurant(): void
    {
        $this->actingAsRole('cashier');
        $first = $this->servedOrder();
        $second = $this->servedOrder();
        $payload = ['method' => 'mobile_money', 'operator' => 'mtn_momo', 'reference' => 'MOM-REUSED'];

        $this->pay($first, $payload)->assertCreated();

        $this->pay($second, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');
        $this->assertSame(0, $second->payments()->count());

        // un autre opérateur peut avoir la même référence
        $this->pay($second, array_merge($payload, ['operator' => 'moov_money']))->assertCreated();

        // un autre restaurant aussi
        $other = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $other);
        $foreign = $this->servedOrder($other);

        $this->pay($foreign, $payload)->assertCreated();
    }
}
