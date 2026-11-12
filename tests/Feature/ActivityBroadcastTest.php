<?php

namespace Tests\Feature;

use App\Events\ActivityCreated;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'capacity' => 4,
            'status' => 'free',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
            'name' => 'Test '.$role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------------
    // 1. Broadcast sur action à haute priorité (order.created)
    // ------------------------------------------------------------------

    public function test_broadcast_fires_on_order_created(): void
    {
        Event::fake([ActivityCreated::class]);

        $this->actingAsRole('waiter');

        $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
        ])->assertCreated();

        Event::assertDispatched(ActivityCreated::class, function ($event) {
            return $event->action === 'order.created'
                && $event->restaurantId === $this->restaurant->id
                && $event->entityType === 'order';
        });
    }

    // ------------------------------------------------------------------
    // 2. Pas de broadcast sur action à basse priorité (menu.item_updated)
    // ------------------------------------------------------------------

    public function test_broadcast_does_not_fire_on_low_priority_action(): void
    {
        Event::fake([ActivityCreated::class]);

        $this->actingAsRole('admin');

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Yassa',
            'price' => 5000,
        ]);

        $this->putJson("/api/menu/{$dish->id}", [
            'name' => 'Yassa revisité',
        ])->assertOk();

        Event::assertNotDispatched(ActivityCreated::class);
    }

    // ------------------------------------------------------------------
    // 3. Le broadcast ne casse pas la réponse si Reverb est absent
    // ------------------------------------------------------------------

    public function test_broadcast_does_not_break_http_when_reverb_is_down(): void
    {
        // Pas de Event::fake() → le broadcast est tenté réellement
        // Si Reverb est absent, ça doit être catch silencieusement

        $this->actingAsRole('waiter');

        $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
        ])->assertCreated();

        // Aucune assertion sur l'event, on vérifie juste que la requête HTTP passe
        $this->assertTrue(true);
    }
}
