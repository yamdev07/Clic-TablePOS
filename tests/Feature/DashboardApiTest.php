<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private function otherRestaurant(): Restaurant
    {
        return Restaurant::factory()->create(['timezone' => 'Africa/Porto-Novo']);
    }

    private function dish(Restaurant $restaurant, string $name): MenuItem
    {
        return MenuItem::factory()->create(['restaurant_id' => $restaurant->id, 'name' => $name]);
    }

    private function line(Order $order, MenuItem $dish, int $quantity, int $unitPrice = 1000, array $attrs = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $dish->id,
            'item_name' => $dish->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'kitchen_status' => 'pending',
        ], $attrs));
    }

    private function confirmedOrder(Restaurant $restaurant, string $confirmedAtUtc): Order
    {
        return $this->orderFor($restaurant, 'in_progress', $confirmedAtUtc, [
            'confirmed_at' => $this->at($confirmedAtUtc),
        ]);
    }

    private function log(Restaurant $restaurant, string $description, string $atUtc): ActivityLog
    {
        $log = new ActivityLog([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'user_id' => $this->admin->id,
            'user_name' => 'Admin',
            'action' => 'order.created',
            'description' => $description,
            'entity_type' => 'order',
            'entity_id' => (string) Str::uuid(),
            'old_values' => [],
            'new_values' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
        $log->created_at = $this->at($atUtc);
        $log->save();

        return $log;
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
    // ── Restaurant vide : graphique, service, ventes, flux ──

    public function test_empty_restaurant_returns_empty_details(): void
    {
        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sales.values', [0, 0, 0, null, null, null, null])
            ->assertJsonPath('sales.average', 0)
            ->assertJsonPath('sales.trend', null)
            ->assertJsonPath('service', ['avg_wait_minutes' => null, 'ready_under_15_pct' => null])
            ->assertJsonPath('best_sellers', [])
            ->assertJsonPath('activity', []);
    }

    // ── Graphique des ventes ──

    public function test_week_sales_group_payments_by_local_day(): void
    {
        $this->payment($this->restaurant, 1000, '2026-11-02 12:00:00');                // lundi
        $this->payment($this->restaurant, 500, '2026-11-01 23:30:00');                 // lundi 00:30 locale
        $this->payment($this->restaurant, 1500, '2026-11-03 12:00:00');                // mardi
        $this->payment($this->restaurant, 3000, '2026-11-04 08:00:00');                // mercredi
        $this->payment($this->restaurant, 9999, '2026-11-04 09:00:00', 'failed');      // échoué : ignoré
        $this->payment($this->restaurant, 8888, '2026-11-01 12:00:00');                // dimanche précédent : hors semaine
        $this->payment($this->otherRestaurant(), 7777, '2026-11-04 08:00:00');         // autre restaurant : ignoré

        $this->loginAdmin();

        $this->getJson('/api/dashboard?period=week')
            ->assertOk()
            ->assertJsonPath('sales.labels', ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
            ->assertJsonPath('sales.values', [1500, 1500, 3000, null, null, null, null])
            ->assertJsonPath('sales.average', 2000);
    }

    public function test_month_sales_have_one_value_per_day(): void
    {
        $this->payment($this->restaurant, 1000, '2026-11-02 12:00:00');
        $this->payment($this->restaurant, 3000, '2026-11-04 08:00:00');
        $this->payment($this->restaurant, 6666, '2026-10-31 12:00:00');   // mois précédent : ignoré

        $this->loginAdmin();

        $response = $this->getJson('/api/dashboard?period=month')->assertOk();

        $labels = $response->json('sales.labels');
        $values = $response->json('sales.values');

        $this->assertCount(30, $labels);
        $this->assertSame('1', $labels[0]);
        $this->assertSame('30', $labels[29]);
        $this->assertSame([0, 1000, 0, 3000], array_slice($values, 0, 4));
        $this->assertNull($values[4]);
        $this->assertNull($values[29]);
        $response->assertJsonPath('sales.average', 1000);
    }

    public function test_trend_is_up_when_revenue_grows(): void
    {
        $this->payment($this->restaurant, 6000, '2026-11-03 12:00:00');    // mardi, cette semaine
        $this->payment($this->restaurant, 3000, '2026-10-27 12:00:00');    // mardi précédent
        $this->payment($this->restaurant, 99999, '2026-10-30 12:00:00');   // vendredi précédent : hors comparaison

        $this->loginAdmin();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('sales.trend', 'up');
    }

    public function test_trend_is_down_when_revenue_drops(): void
    {
        $this->payment($this->restaurant, 6000, '2026-11-03 12:00:00');
        $this->payment($this->restaurant, 12000, '2026-10-27 12:00:00');

        $this->loginAdmin();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('sales.trend', 'down');
    }

    public function test_trend_is_stable_within_five_percent(): void
    {
        $this->payment($this->restaurant, 6000, '2026-11-03 12:00:00');
        $this->payment($this->restaurant, 5800, '2026-10-27 12:00:00');   // +3,4 %

        $this->loginAdmin();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('sales.trend', 'stable');
    }

    public function test_trend_is_null_without_previous_revenue(): void
    {
        $this->payment($this->restaurant, 6000, '2026-11-03 12:00:00');

        $this->loginAdmin();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('sales.trend', null);
    }

    // ── Temps de service ──

    public function test_service_time_is_computed_from_confirmation_to_ready(): void
    {
        $dish = $this->dish($this->restaurant, 'Poulet DG');
        $order = $this->confirmedOrder($this->restaurant, '2026-11-04 08:00:00');

        $this->line($order, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-04 08:10:00')]);   // 10 min
        $this->line($order, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-04 08:15:00')]);   // 15 min : limite incluse
        $this->line($order, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-04 08:20:00')]);   // 20 min

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('service.avg_wait_minutes', 15)
            ->assertJsonPath('service.ready_under_15_pct', 66.7);
    }

    public function test_service_time_ignores_unrelated_items(): void
    {
        $dish = $this->dish($this->restaurant, 'Poulet DG');

        $valid = $this->confirmedOrder($this->restaurant, '2026-11-04 08:00:00');
        $this->line($valid, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-04 08:10:00')]);   // 10 min : le seul retenu

        // prêt hier : ignoré
        $yesterday = $this->confirmedOrder($this->restaurant, '2026-11-03 08:00:00');
        $this->line($yesterday, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-03 09:00:00')]);

        // commande jamais envoyée en cuisine : ignorée
        $unconfirmed = $this->orderFor($this->restaurant, 'open', '2026-11-04 08:00:00');
        $this->line($unconfirmed, $dish, 1, 1000, ['ready_at' => $this->at('2026-11-04 09:00:00')]);

        // autre restaurant : ignoré
        $other = $this->otherRestaurant();
        $otherOrder = $this->confirmedOrder($other, '2026-11-04 08:00:00');
        $this->line($otherOrder, $this->dish($other, 'Autre'), 1, 1000, ['ready_at' => $this->at('2026-11-04 10:00:00')]);

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('service.avg_wait_minutes', 10)
            ->assertJsonPath('service.ready_under_15_pct', 100);
    }

    // ── Meilleures ventes ──

    public function test_best_sellers_rank_dishes_by_quantity_then_revenue(): void
    {
        $thieb = $this->dish($this->restaurant, 'Thiéboudienne');
        $poulet = $this->dish($this->restaurant, 'Poulet DG');
        $coca = $this->dish($this->restaurant, 'Coca');

        $paid = $this->orderFor($this->restaurant, 'paid', '2026-11-04 08:00:00');
        $open = $this->orderFor($this->restaurant, 'open', '2026-11-04 09:00:00');

        $this->line($paid, $thieb, 2, 7000);
        $this->line($open, $thieb, 3, 7000);    // 5 au total : 35 000
        $this->line($paid, $poulet, 4, 6000);   // 4 : 24 000
        $this->line($open, $coca, 4, 1000);     // 4 : 4 000 (même quantité, moins de recettes)

        // Ignorés : commande annulée, commande d'hier, autre restaurant
        $this->line($this->orderFor($this->restaurant, 'cancelled', '2026-11-04 08:00:00'), $coca, 50);
        $this->line($this->orderFor($this->restaurant, 'paid', '2026-11-03 12:00:00'), $coca, 50);
        $other = $this->otherRestaurant();
        $this->line($this->orderFor($other, 'paid', '2026-11-04 08:00:00'), $this->dish($other, 'Autre'), 99);

        $this->loginAdmin();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('best_sellers', [
                ['menu_item_id' => $thieb->id, 'name' => 'Thiéboudienne', 'quantity' => 5, 'revenue' => 35000],
                ['menu_item_id' => $poulet->id, 'name' => 'Poulet DG', 'quantity' => 4, 'revenue' => 24000],
                ['menu_item_id' => $coca->id, 'name' => 'Coca', 'quantity' => 4, 'revenue' => 4000],
            ]);
    }

    public function test_best_sellers_are_limited_to_five(): void
    {
        $order = $this->orderFor($this->restaurant, 'paid', '2026-11-04 08:00:00');

        foreach (range(1, 6) as $quantity) {
            $this->line($order, $this->dish($this->restaurant, "Plat $quantity"), $quantity);
        }

        $this->loginAdmin();

        $names = array_column($this->getJson('/api/dashboard')->assertOk()->json('best_sellers'), 'name');

        $this->assertSame(['Plat 6', 'Plat 5', 'Plat 4', 'Plat 3', 'Plat 2'], $names);
    }

    // ── Flux d'activité ──

    public function test_activity_feed_returns_the_ten_latest_events_of_the_restaurant(): void
    {
        foreach (range(1, 12) as $i) {
            $this->log($this->restaurant, "Événement $i", sprintf('2026-11-04 08:%02d:00', $i));
        }
        $this->log($this->otherRestaurant(), 'Événement autre restaurant', '2026-11-04 11:00:00');

        $this->loginAdmin();

        $activity = $this->getJson('/api/dashboard')->assertOk()->json('activity');

        $this->assertCount(10, $activity);
        $this->assertSame('Événement 12', $activity[0]['description']);
        $this->assertSame('Événement 3', $activity[9]['description']);
        $this->assertNotContains('Événement autre restaurant', array_column($activity, 'description'));

        // Pas d'IP, ni de navigateur, ni d'anciennes valeurs
        foreach ($activity as $event) {
            $this->assertSame(
                ['id', 'action', 'description', 'user_name', 'entity_type', 'created_at'],
                array_keys($event)
            );
        }
    }
}
