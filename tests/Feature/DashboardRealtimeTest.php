<?php

namespace Tests\Feature;

use App\Events\DashboardActivity;
use App\Models\ActivityLog;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->waiter = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);
    }

    private function createLog(string $description = 'Commande créée'): ActivityLog
    {
        return ActivityLog::create([
            'restaurant_id' => $this->restaurant->id,
            'user_id' => $this->waiter->id,
            'user_name' => $this->waiter->name,
            'action' => 'order.created',
            'description' => $description,
            'entity_type' => 'order',
            'entity_id' => (string) Str::uuid(),
            'old_values' => [],
            'new_values' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
    }

    public function test_a_new_log_entry_is_broadcast_on_the_dashboard_channel(): void
    {
        Event::fake([DashboardActivity::class]);

        $log = $this->createLog();

        Event::assertDispatched(DashboardActivity::class, function (DashboardActivity $event) use ($log) {
            return $event->restaurantId === $this->restaurant->id
                && $event->entry['id'] === $log->id
                && $event->broadcastOn()[0]->name === 'private-dashboard.'.$this->restaurant->id;
        });
    }

    public function test_broadcast_payload_only_contains_the_feed_fields(): void
    {
        Event::fake([DashboardActivity::class]);

        $this->createLog();

        Event::assertDispatched(DashboardActivity::class, function (DashboardActivity $event) {
            return array_keys($event->broadcastWith()) === [
                'id', 'action', 'description', 'user_name', 'entity_type', 'created_at',
            ];
        });
    }

    public function test_creating_an_order_broadcasts_to_its_own_restaurant(): void
    {
        Event::fake([DashboardActivity::class]);

        $table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
        ]);

        Sanctum::actingAs($this->waiter);
        $this->postJson('/api/orders', ['table_id' => $table->id])->assertCreated();

        Event::assertDispatchedTimes(DashboardActivity::class, 1);
        Event::assertDispatched(DashboardActivity::class, fn (DashboardActivity $event) => $event->restaurantId === $this->restaurant->id
            && $event->entry['action'] === 'order.created');
    }

    public function test_a_broadcast_failure_does_not_prevent_logging(): void
    {
        // Simule un serveur temps réel indisponible
        Event::listen(DashboardActivity::class, fn () => throw new \RuntimeException('Reverb indisponible'));

        $log = $this->createLog();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
    }
}
