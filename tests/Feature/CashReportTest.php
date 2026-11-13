<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashReportTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private CashRegister $register;

    private Table $table;

    private MenuItem $dish;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->cashier = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'cashier',
            'name' => 'Awa Koffi',
        ]);
        $this->register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse principale',
            'is_active' => true,
        ]);
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

    private function actingAsRole(string $role = 'cashier'): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createSession(string $status = 'open'): CashSession
    {
        return CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $this->register->id,
            'status' => $status,
            'opening_balance' => 50000,
            'opened_by' => $this->cashier->id,
            'opened_at' => now(),
        ]);
    }

    private function createPayment(CashSession $session, int $amount, string $method = 'cash'): Payment
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->cashier->id,
            'status' => 'paid',
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => $amount,
            'total_price' => $amount,
            'options_total' => 0,
            'kitchen_status' => 'served',
        ]);

        return Payment::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'user_id' => $this->cashier->id,
            'payment_group_id' => null,
            'cash_session_id' => $method === 'cash' ? $session->id : null,
            'amount' => $amount,
            'method' => $method,
            'operator' => $method === 'mobile_money' ? 'mtn_momo' : null,
            'reference' => $method === 'mobile_money' ? 'MOMO-'.$amount : null,
            'transaction_data' => ['receipt_confirmed_at' => now()->toIso8601String()],
            'status' => 'completed',
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_authorized_roles_can_see_the_report(): void
    {
        $this->actingAsRole('cashier');
        $session = $this->createSession();

        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/cash/sessions/{$session->id}/report")->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/cash/sessions/{$session->id}/report")->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // 2. Session fermée : rapport complet avec figement
    // ------------------------------------------------------------------

    public function test_closed_session_returns_the_full_frozen_report(): void
    {
        $this->actingAsRole('cashier');
        $session = $this->createSession();

        // 2 paiements cash = 150 000 + 50 000 = 200 000
        $this->createPayment($session, 150000);
        $this->createPayment($session, 50000);

        // 1 mouvement in + 1 mouvement out
        CashMovement::create([
            'id' => (string) Str::uuid(),
            'cash_session_id' => $session->id,
            'type' => 'in',
            'amount' => 10000,
            'reason' => 'Apport',
            'created_by' => $this->cashier->id,
        ]);
        CashMovement::create([
            'id' => (string) Str::uuid(),
            'cash_session_id' => $session->id,
            'type' => 'out',
            'amount' => 30000,
            'reason' => 'Dépôt banque',
            'created_by' => $this->cashier->id,
        ]);

        // Fermer avec 178 500 comptés
        // expected = 50000 + 200000 + 10000 - 30000 = 230000
        $this->postJson("/api/cash/sessions/{$session->id}/close", [
            'closing_balance' => 178500,
        ])->assertOk();

        $data = $this->getJson("/api/cash/sessions/{$session->id}/report")
            ->assertOk()
            ->json();

        $this->assertSame($session->id, $data['session_id']);
        $this->assertSame('closed', $data['status']);
        $this->assertSame(50000, $data['opening_balance']);
        $this->assertSame(200000, $data['cash_collected']);
        $this->assertSame(10000, $data['movements_in']);
        $this->assertSame(30000, $data['movements_out']);
        $this->assertSame(230000, $data['expected_balance']);
        $this->assertSame(178500, $data['closing_balance']);
        $this->assertSame(-51500, $data['difference']);
        $this->assertSame(2, $data['payments_count']);
        $this->assertCount(2, $data['movements']);
        $this->assertSame('Awa Koffi', $data['opened_by']['name']);

        // Le snapshot doit être figé
        $this->assertNotNull($session->fresh()->report_snapshot);
    }

    // ------------------------------------------------------------------
    // 3. Session ouverte : rapport partiel (expected_balance, pas de closing)
    // ------------------------------------------------------------------

    public function test_open_session_returns_partial_report(): void
    {
        $this->actingAsRole('cashier');
        $session = $this->createSession();

        $this->createPayment($session, 100000);

        $data = $this->getJson("/api/cash/sessions/{$session->id}/report")
            ->assertOk()
            ->json();

        $this->assertSame('open', $data['status']);
        $this->assertSame(50000, $data['opening_balance']);
        $this->assertSame(100000, $data['cash_collected']);
        $this->assertSame(150000, $data['expected_balance']);
        $this->assertNull($data['closing_balance']);
        $this->assertNull($data['difference']);
        $this->assertNull($data['closed_at']);
    }

    // ------------------------------------------------------------------
    // 4. Session fermée renvoie TOUJOURS le snapshot figé (immuable)
    // ------------------------------------------------------------------

    public function test_closed_session_report_is_frozen(): void
    {
        $this->actingAsRole('cashier');
        $session = $this->createSession();
        $this->createPayment($session, 100000);

        $this->postJson("/api/cash/sessions/{$session->id}/close", [
            'closing_balance' => 150000,
        ])->assertOk();

        $firstData = $this->getJson("/api/cash/sessions/{$session->id}/report")->json();

        // Simuler une corruption post-figement (mouvement ajouté après, impossible normalement)
        CashMovement::create([
            'id' => (string) Str::uuid(),
            'cash_session_id' => $session->id,
            'type' => 'in',
            'amount' => 99999,
            'reason' => 'Anomalie',
            'created_by' => $this->cashier->id,
        ]);

        $secondData = $this->getJson("/api/cash/sessions/{$session->id}/report")->json();

        // Le rapport reste identique (figé)
        $this->assertSame($firstData['expected_balance'], $secondData['expected_balance']);
        $this->assertSame($firstData['movements_in'], $secondData['movements_in']);
    }

    // ------------------------------------------------------------------
    // 5. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_report_is_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherRegister = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $other->id,
            'name' => 'Caisse B',
            'is_active' => true,
        ]);
        $otherSession = CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $otherRegister->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_by' => User::factory()->create(['restaurant_id' => $other->id])->id,
            'opened_at' => now(),
        ]);

        $this->actingAsRole('cashier');
        $this->getJson("/api/cash/sessions/{$otherSession->id}/report")->assertNotFound();
    }
}
