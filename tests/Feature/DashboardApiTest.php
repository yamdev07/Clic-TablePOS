<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $admin;

    private array $serviceTables = [];

    private array $serviceUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 4 novembre 2026, 12:00 UTC = 13:00 à Cotonou
        $this->travelTo($this->at('2026-11-04 12:00:00'));

        $this->restaurant = Restaurant::factory()->create(['timezone' => 'Africa/Porto-Novo']);
        $this->admin = $this->userWithRole($this->restaurant, 'admin');
    }

    // ── Outils ──

    /** Date UTC convertie dans le fuseau d'écriture de Laravel. */
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'));
    }

    private function userWithRole(Restaurant $restaurant, string $role): User
    {
        return User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => $role]);
    }

    private function loginAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function tables(Restaurant $restaurant, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Table::factory()->create([
                'restaurant_id' => $restaurant->id,
                'status' => $status,
                'number' => $status.'-'.$i,
            ]);
        }
    }

    private function orderFor(Restaurant $restaurant, string $status, string $atUtc, array $attrs = []): Order
    {
        $this->serviceTables[$restaurant->id] ??= Table::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => 'free',
            'number' => 'service',
        ]);
        $this->serviceUsers[$restaurant->id] ??= $this->userWithRole($restaurant, 'waiter');

        return Order::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'table_id' => $this->serviceTables[$restaurant->id]->id,
            'user_id' => $this->serviceUsers[$restaurant->id]->id,
            'status' => $status,
            'created_at' => $this->at($atUtc),
        ], $attrs));
    }

    /** Un paiement, rattaché à une commande payée créée à la même heure. */
    private function payment(Restaurant $restaurant, int $amount, string $atUtc, string $status = 'completed'): Payment
    {
        $order = $this->orderFor($restaurant, 'paid', $atUtc);

        return Payment::factory()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => $amount,
            'method' => 'cash',
            'status' => $status,
            'created_at' => $this->at($atUtc),
        ]);
    }

    // ── Accès et validation ──

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_waiter_and_kitchen_are_forbidden(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            Sanctum::actingAs($this->userWithRole($this->restaurant, $role));

            $this->getJson('/api/dashboard')->assertForbidden();
        }
    }

    public function test_admin_and_manager_can_access(): void
    {
        foreach (['admin', 'manager'] as $role) {
            Sanctum::actingAs($this->userWithRole($this->restaurant, $role));

            $this->getJson('/api/dashboard')
                ->assertOk()
                ->assertJsonStructure([
                    'period',
                    'cards' => [
                        'tables' => ['active', 'free', 'total'],
                        'revenue' => ['today', 'change_pct'],
                        'orders' => ['total', 'paid', 'in_progress'],
                        'covers' => ['today', 'change_pct'],
                    ],
                ]);
        }
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->loginAdmin();

        $this->getJson('/api/dashboard?period=year')
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_period_defaults_to_week(): void
    {
        $this->loginAdmin();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('period', 'week');
        $this->getJson('/api/dashboard?period=month')->assertOk()->assertJsonPath('period', 'month');
    }

    // ── Restaurant vide ──

    public function test_empty_restaurant_returns_zeros_without_error(): void
    {
        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.tables', ['active' => 0, 'free' => 0, 'total' => 0])
            ->assertJsonPath('cards.revenue.today', 0)
            ->assertJsonPath('cards.revenue.change_pct', null)
            ->assertJsonPath('cards.orders', ['total' => 0, 'paid' => 0, 'in_progress' => 0])
            ->assertJsonPath('cards.covers.today', 0)
            ->assertJsonPath('cards.covers.change_pct', null);
    }

    // ── Isolation entre restaurants ──

    public function test_each_restaurant_only_sees_its_own_figures(): void
    {
        $restaurantB = Restaurant::factory()->create(['timezone' => 'Africa/Porto-Novo']);
        $adminB = $this->userWithRole($restaurantB, 'admin');

        $this->tables($this->restaurant, 'occupied', 2);
        $this->tables($restaurantB, 'occupied', 5);
        $this->payment($this->restaurant, 3000, '2026-11-04 08:00:00');
        $this->payment($restaurantB, 9000, '2026-11-04 08:00:00');

        $this->loginAdmin();
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.tables.active', 2)
            ->assertJsonPath('cards.revenue.today', 3000)
            ->assertJsonPath('cards.orders.total', 1);

        Sanctum::actingAs($adminB);
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.tables.active', 5)
            ->assertJsonPath('cards.revenue.today', 9000)
            ->assertJsonPath('cards.orders.total', 1);
    }

    // ── Carte « Tables » ──

    public function test_tables_card_counts_by_status(): void
    {
        $this->tables($this->restaurant, 'occupied', 2);
        $this->tables($this->restaurant, 'free', 1);
        $this->tables($this->restaurant, 'reserved', 1);

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.tables.active', 2)
            ->assertJsonPath('cards.tables.free', 1)
            ->assertJsonPath('cards.tables.total', 4);
    }

    // ── Carte « Recettes » ──

    public function test_revenue_counts_completed_payments_of_the_local_day(): void
    {
        // Aujourd'hui à Cotonou (UTC+1) = du 3 novembre 23:00 UTC au 4 novembre 22:59 UTC
        $this->payment($this->restaurant, 5000, '2026-11-04 08:00:00');
        $this->payment($this->restaurant, 7000, '2026-11-03 23:30:00');            // 00:30 locale : aujourd'hui
        $this->payment($this->restaurant, 9999, '2026-11-04 09:00:00', 'failed');  // paiement échoué : ignoré

        // Hier à Cotonou
        $this->payment($this->restaurant, 10000, '2026-11-03 22:30:00');           // 23:30 locale : hier
        $this->payment($this->restaurant, 4000, '2026-11-03 12:00:00', 'pending'); // en attente : ignoré

        $this->loginAdmin();

        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('cards.revenue.today', 12000);
        $this->assertEquals(20.0, $response->json('cards.revenue.change_pct'));
    }

    public function test_revenue_change_is_null_when_yesterday_has_no_revenue(): void
    {
        $this->payment($this->restaurant, 5000, '2026-11-04 08:00:00');

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.revenue.today', 5000)
            ->assertJsonPath('cards.revenue.change_pct', null);
    }

    // ── Carte « Commandes » ──

    public function test_orders_card_counts_today_orders_by_status(): void
    {
        $today = '2026-11-04 08:00:00';

        $this->orderFor($this->restaurant, 'paid', $today);
        $this->orderFor($this->restaurant, 'paid', $today);
        $this->orderFor($this->restaurant, 'open', $today);
        $this->orderFor($this->restaurant, 'in_progress', $today);
        $this->orderFor($this->restaurant, 'ready', $today);
        $this->orderFor($this->restaurant, 'cancelled', $today);              // annulée : exclue du total
        $this->orderFor($this->restaurant, 'paid', '2026-11-03 12:00:00');    // hier : exclue

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('cards.orders.total', 5)
            ->assertJsonPath('cards.orders.paid', 2)
            ->assertJsonPath('cards.orders.in_progress', 3);
    }

    // ── Carte « Couverts » ──

    public function test_covers_sum_guests_of_paid_and_served_orders(): void
    {
        $today = '2026-11-04 08:00:00';

        $this->orderFor($this->restaurant, 'paid', $today, ['guest_count' => 4]);
        $this->orderFor($this->restaurant, 'served', $today, ['guest_count' => 2]);
        $this->orderFor($this->restaurant, 'open', $today, ['guest_count' => 5]);   // pas encore servie : exclue
        $this->orderFor($this->restaurant, 'paid', $today);                         // couverts non saisis : 0
        $this->orderFor($this->restaurant, 'paid', '2026-11-03 12:00:00', ['guest_count' => 3]);

        $this->loginAdmin();

        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('cards.covers.today', 6);
        $this->assertEquals(100.0, $response->json('cards.covers.change_pct'));
    }
}
