<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZReportHistoryTest extends TestCase
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

    private function closeFor(string $date, ?Restaurant $restaurant = null): ZReport
    {
        $restaurant ??= $this->restaurant;

        $closedBy = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => 'cashier',
            'name' => 'Caissier '.$restaurant->id,
        ]);

        $sequence = (ZReport::where('restaurant_id', $restaurant->id)
            ->where('date', $date)
            ->max('sequence_number') ?? 0) + 1;

        return ZReport::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'date' => $date,
            'sequence_number' => $sequence,
            'code' => 'Z-'.str_replace('-', '', $date).'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'snapshot' => [
                'date' => $date,
                'total_revenue' => 1230,
                'paid_orders' => 1,
            ],
            'cash_counted' => null,
            'cash_difference' => null,
            'closed_by' => $closedBy->id,
            'closed_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_admin_manager_and_cashier_can_see_z_report_history(): void
    {
        $this->closeFor(now('Africa/Porto-Novo')->toDateString());

        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/stats/z-report/closed')->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/stats/z-report/closed')->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // 2. Isolation
    // ------------------------------------------------------------------

    public function test_history_is_isolated_across_restaurants(): void
    {
        $this->closeFor('2026-11-08', $this->restaurant);

        $other = Restaurant::factory()->create();
        $this->closeFor('2026-11-09', $other);
        $this->closeFor('2026-11-10', $other);

        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/stats/z-report/closed')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('2026-11-08', $data[0]['date']);
    }

    // ------------------------------------------------------------------
    // 3. Liste sans snapshot
    // ------------------------------------------------------------------

    public function test_history_lists_closed_reports_without_snapshot(): void
    {
        $this->closeFor('2026-11-08', $this->restaurant);
        $this->closeFor('2026-11-09', $this->restaurant);

        $this->actingAsRole('cashier');
        $data = $this->getJson('/api/stats/z-report/closed')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('date', $first);
        $this->assertArrayHasKey('sequence_number', $first);
        $this->assertArrayHasKey('closed_at', $first);
        $this->assertArrayHasKey('closed_by', $first);
        $this->assertArrayNotHasKey('snapshot', $first);
    }

    // ------------------------------------------------------------------
    // 4. Filtre date
    // ------------------------------------------------------------------

    public function test_history_filters_by_date_range(): void
    {
        $this->closeFor('2026-11-08', $this->restaurant);
        $this->closeFor('2026-11-09', $this->restaurant);
        $this->closeFor('2026-11-10', $this->restaurant);

        $this->actingAsRole('cashier');

        $data = $this->getJson('/api/stats/z-report/closed?from=2026-11-09')->assertOk()->json('data');
        $this->assertCount(2, $data);

        $data = $this->getJson('/api/stats/z-report/closed?to=2026-11-09')->assertOk()->json('data');
        $this->assertCount(2, $data);

        $data = $this->getJson('/api/stats/z-report/closed?from=2026-11-09&to=2026-11-09')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('2026-11-09', $data[0]['date']);
    }

    // ------------------------------------------------------------------
    // 5. Pagination
    // ------------------------------------------------------------------

    public function test_history_pagination_respects_per_page_and_cap(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->closeFor('2026-11-0'.$i, $this->restaurant);
        }

        $this->actingAsRole('cashier');

        $data = $this->getJson('/api/stats/z-report/closed?per_page=2')->assertOk()->json();
        $this->assertCount(2, $data['data']);
        $this->assertSame(2, $data['meta']['per_page']);
        $this->assertSame(5, $data['meta']['total']);

        $data = $this->getJson('/api/stats/z-report/closed?per_page=500')->assertOk()->json();
        $this->assertSame(100, $data['meta']['per_page']);
    }

    // ------------------------------------------------------------------
    // 6. Détail avec snapshot figé
    // ------------------------------------------------------------------

    public function test_history_detail_returns_the_frozen_snapshot(): void
    {
        $report = $this->closeFor('2026-11-08', $this->restaurant);
        $report->update(['cash_counted' => 1000, 'cash_difference' => -230]);

        $this->actingAsRole('cashier');
        $data = $this->getJson("/api/stats/z-report/closed/{$report->id}")->assertOk()->json();

        $this->assertSame('2026-11-08', $data['date']);
        $this->assertSame(1230, $data['snapshot']['total_revenue']);
        $this->assertSame(1, $data['snapshot']['paid_orders']);
        $this->assertSame(1000, $data['cash_counted']);
        $this->assertSame(-230, $data['cash_difference']);
        $this->assertArrayHasKey('name', $data['closed_by']);
        $this->assertSame('cashier', $data['closed_by']['role'] ?? null);
    }

    // ------------------------------------------------------------------
    // 7. Détail d'un autre restaurant → 404
    // ------------------------------------------------------------------

    public function test_detail_of_another_restaurant_returns_404(): void
    {
        $other = Restaurant::factory()->create();
        $foreign = $this->closeFor('2026-11-08', $other);

        $this->actingAsRole('cashier');
        $this->getJson("/api/stats/z-report/closed/{$foreign->id}")->assertNotFound();
    }
}
