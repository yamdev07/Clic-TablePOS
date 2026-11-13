<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentCashSessionLinkTest extends TestCase
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

    private function servedOrder(): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
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

        $order->recalculate();

        return $order->fresh();
    }

    private function openCashSession(): CashSession
    {
        $register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse principale',
            'is_active' => true,
        ]);

        return CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $register->id,
            'status' => 'open',
            'opening_balance' => 50000,
            'opened_by' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'opened_at' => now(),
        ]);
    }

    private function pay(Order $order, array $overrides = [])
    {
        return $this->postJson("/api/orders/{$order->id}/payments", array_merge([
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // 1. Paiement cash avec session ouverte → lié
    // ------------------------------------------------------------------

    public function test_cash_payment_is_attached_to_open_cash_session(): void
    {
        Config::set('cash.require_open_session', false);

        $this->actingAsRole('cashier');
        $session = $this->openCashSession();
        $order = $this->servedOrder();

        $this->pay($order)->assertCreated();

        $payment = $order->payments()->first();
        $this->assertSame($session->id, $payment->cash_session_id);
    }

    // ------------------------------------------------------------------
    // 2. Paiement MM avec session ouverte → NON lié
    // ------------------------------------------------------------------

    public function test_mobile_money_payment_is_not_attached_to_cash_session(): void
    {
        Config::set('cash.require_open_session', false);

        $this->actingAsRole('cashier');
        $session = $this->openCashSession();
        $order = $this->servedOrder();

        $this->pay($order, [
            'method' => 'mobile_money',
            'operator' => 'mtn_momo',
            'reference' => 'MOMO-1',
        ])->assertCreated();

        $payment = $order->payments()->first();
        $this->assertNull($payment->cash_session_id);
    }

    // ------------------------------------------------------------------
    // 3. Contrainte active + pas de session → 422
    // ------------------------------------------------------------------

    public function test_cash_payment_without_open_session_is_refused_when_required(): void
    {
        Config::set('cash.require_open_session', true);

        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        $this->pay($order)
            ->assertStatus(422)
            ->assertJson(['message' => "Aucune session de caisse ouverte. Ouvrez la caisse avant d'encaisser en espèces."]);

        $this->assertSame(0, $order->payments()->count());
    }

    // ------------------------------------------------------------------
    // 4. Contrainte inactive + pas de session → 201 avec cash_session_id = null
    // ------------------------------------------------------------------

    public function test_cash_payment_without_open_session_works_when_not_required(): void
    {
        Config::set('cash.require_open_session', false);

        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        $this->pay($order)->assertCreated();

        $payment = $order->payments()->first();
        $this->assertNull($payment->cash_session_id);
    }
}
