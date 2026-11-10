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
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZReportCloseTest extends TestCase
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

        if ($restaurant->is($this->restaurant)) {
            $table = $this->table;
        } else {
            $table = Table::firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'number' => '9'],
                [
                    'id' => (string) Str::uuid(),
                    'capacity' => 2,
                    'status' => 'occupied',
                    'qr_code' => 'test-'.$restaurant->id,
                ]
            );
        }

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'status' => 'served',
            'guest_count' => 2,
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

    private function close(array $payload = [])
    {
        return $this->postJson('/api/stats/z-report/close', $payload);
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_admin_manager_and_cashier_can_close_the_z_report(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->close()->assertForbidden();
            $this->assertDatabaseCount('z_reports', 0);
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->close()->assertCreated();
            $this->assertDatabaseCount('z_reports', 1);
            // On supprime pour tester le rôle suivant sans conflit
            ZReport::query()->delete();
        }
    }

    // ------------------------------------------------------------------
    // 2. Numérotation séquentielle par jour
    // ------------------------------------------------------------------

    public function test_closing_creates_a_z_report_with_a_sequential_number(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->close()->assertCreated();

        $today = now('Africa/Porto-Novo')->toDateString();
        $expectedCode = 'Z-'.now('Africa/Porto-Novo')->format('Ymd').'-0001';

        $response->assertJson([
            'sequence_number' => 1,
            'code' => $expectedCode,
            'date' => $today,
        ]);

        $this->assertDatabaseHas('z_reports', [
            'restaurant_id' => $this->restaurant->id,
            'date' => $today,
            'sequence_number' => 1,
            'code' => $expectedCode,
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Double clôture même jour = 422
    // ------------------------------------------------------------------

    public function test_a_second_close_on_the_same_day_is_refused(): void
    {
        $this->actingAsRole('cashier');

        $this->close()->assertCreated();
        $this->close()->assertStatus(422);

        $this->assertDatabaseCount('z_reports', 1);
    }

    // ------------------------------------------------------------------
    // 4. Snapshot figé
    // ------------------------------------------------------------------

    public function test_a_z_report_snapshots_the_current_state(): void
    {
        $this->actingAsRole('cashier');

        // 2 commandes payées, 1 servie non payée
        $this->pay($this->servedOrder())->assertCreated();
        $this->pay($this->servedOrder())->assertCreated();
        $this->servedOrder();

        $response = $this->close()->assertCreated();

        $this->assertSame(2460, $response->json('snapshot.total_revenue'));
        $this->assertSame(2460, $response->json('snapshot.total_paid'));
        $this->assertSame(2, $response->json('snapshot.paid_orders'));
        $this->assertSame(1, $response->json('snapshot.unclosed_orders'));
        $this->assertSame(1230, $response->json('snapshot.unclosed_amount'));
        $this->assertSame(4, $response->json('snapshot.guests_count')); // 2 + 2 payées
        $this->assertIsArray($response->json('snapshot.payment_breakdown'));
    }

    // ------------------------------------------------------------------
    // 5. GET renvoie le snapshot figé
    // ------------------------------------------------------------------

    public function test_a_closed_z_report_is_returned_by_get_z_report(): void
    {
        $this->actingAsRole('cashier');

        $this->pay($this->servedOrder())->assertCreated();
        $closed = $this->close()->assertCreated();

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        $this->assertTrue($data['closed']);
        $this->assertSame($closed->json('code'), $data['sequence_code']);
        $this->assertSame(1230, $data['total_revenue']);
        $this->assertNotNull($data['closed_at']);
    }

    // ------------------------------------------------------------------
    // 6. Nouveau paiement après clôture → snapshot inchangé
    // ------------------------------------------------------------------

    public function test_a_new_payment_after_close_does_not_change_the_snapshot(): void
    {
        $this->actingAsRole('cashier');

        $this->pay($this->servedOrder())->assertCreated();
        $this->close()->assertCreated();

        // Un nouveau paiement après clôture
        $this->pay($this->servedOrder())->assertCreated();

        $data = $this->getJson('/api/stats/z-report')->assertOk()->json();

        // Le snapshot reste figé : total_revenue ne bouge pas
        $this->assertSame(1230, $data['total_revenue']);
        $this->assertSame(1, $data['paid_orders']);
    }

    // ------------------------------------------------------------------
    // 7. Numérotation : nouveau jour → 0001
    // ------------------------------------------------------------------

    public function test_the_sequence_number_restarts_on_a_new_day(): void
    {
        $this->actingAsRole('cashier');

        // Clôture J-1
        $yesterday = now('Africa/Porto-Novo')->subDay()->toDateString();
        $this->close(['date' => $yesterday])->assertCreated();

        // Clôture aujourd'hui
        $response = $this->close()->assertCreated();
        $this->assertSame(1, $response->json('sequence_number'));
    }

    // ------------------------------------------------------------------
    // 8. cash_counted → cash_difference
    // ------------------------------------------------------------------

    public function test_cash_counted_records_the_difference(): void
    {
        $this->actingAsRole('cashier');

        // 1 paiement cash de 1230 (donc cash_amount = 1230)
        $this->pay($this->servedOrder())->assertCreated();

        $response = $this->close(['cash_counted' => 1000])->assertCreated();

        $this->assertSame(1000, $response->json('cash_counted'));
        $this->assertSame(-230, $response->json('cash_difference'));
    }

    // ------------------------------------------------------------------
    // 9. Isolation
    // ------------------------------------------------------------------

    public function test_closing_is_isolated_across_restaurants(): void
    {
        $this->actingAsRole('cashier');
        $this->close()->assertCreated();

        $other = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $other);
        $this->close()->assertCreated();   // pas de conflit avec A

        $this->assertSame(2, ZReport::count());
    }

    // ------------------------------------------------------------------
    // 10. Clôture d'une date passée
    // ------------------------------------------------------------------

    public function test_closing_a_past_date_works(): void
    {
        $this->actingAsRole('cashier');

        $yesterday = now('Africa/Porto-Novo')->subDay()->toDateString();
        $response = $this->close(['date' => $yesterday])->assertCreated();

        $this->assertSame($yesterday, $response->json('date'));
        $this->assertStringContainsString(
            now('Africa/Porto-Novo')->subDay()->format('Ymd'),
            $response->json('code')
        );
    }
}
