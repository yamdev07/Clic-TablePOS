<?php

namespace Tests\Feature;

use App\Events\TableLayoutChanged;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableLayoutBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
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

    private function makeTable(?string $number = null): Table
    {
        return Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => $number ?? Str::random(6),
            'capacity' => 4,
            'status' => 'free',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Broadcast sur layout update
    // ------------------------------------------------------------------

    public function test_broadcast_fires_on_layout_update(): void
    {
        Event::fake([TableLayoutChanged::class]);

        $this->actingAsRole('admin');
        $t1 = $this->makeTable('1');
        $t2 = $this->makeTable('2');

        $this->putJson('/api/tables/layout', [
            'tables' => [
                ['id' => $t1->id, 'x_position' => 100, 'y_position' => 200],
                ['id' => $t2->id, 'x_position' => 300, 'y_position' => 400],
            ],
        ])->assertOk();

        Event::assertDispatched(TableLayoutChanged::class, function ($event) {
            return $event->action === 'moved'
                && $event->restaurantId === $this->restaurant->id
                && $event->tableId === null
                && is_array($event->data)
                && count($event->data['tables']) === 2;
        });
    }

    // ------------------------------------------------------------------
    // 2. Broadcast sur rotation
    // ------------------------------------------------------------------

    public function test_broadcast_fires_on_rotation(): void
    {
        Event::fake([TableLayoutChanged::class]);

        $this->actingAsRole('waiter');
        $table = $this->makeTable();

        $this->patchJson("/api/tables/{$table->id}/rotate", [
            'rotation' => 90,
        ])->assertOk();

        Event::assertDispatched(TableLayoutChanged::class, function ($event) use ($table) {
            return $event->action === 'rotated'
                && $event->tableId === $table->id
                && $event->data['rotation'] === 90;
        });
    }

    // ------------------------------------------------------------------
    // 3. Broadcast sur changement de modèle
    // ------------------------------------------------------------------

    public function test_broadcast_fires_on_model_change(): void
    {
        Event::fake([TableLayoutChanged::class]);

        $this->actingAsRole('admin');
        $table = $this->makeTable();

        $this->patchJson("/api/tables/{$table->id}/model", [
            'shape' => 'round',
            'size_label' => '2p',
        ])->assertOk();

        Event::assertDispatched(TableLayoutChanged::class, function ($event) use ($table) {
            return $event->action === 'model_changed'
                && $event->tableId === $table->id
                && $event->data['shape'] === 'round'
                && $event->data['size_label'] === '2p';
        });
    }

    // ------------------------------------------------------------------
    // 4. Pas de broadcast sur échec de validation
    // ------------------------------------------------------------------

    public function test_broadcast_does_not_fire_on_failed_update(): void
    {
        Event::fake([TableLayoutChanged::class]);

        $this->actingAsRole('admin');
        $table = $this->makeTable();

        // Rotation invalide (45) → 422, pas de broadcast
        $this->patchJson("/api/tables/{$table->id}/rotate", [
            'rotation' => 45,
        ])->assertStatus(422);

        Event::assertNotDispatched(TableLayoutChanged::class);
    }

    // ------------------------------------------------------------------
    // 5. Aucun broadcast sur 403 (mauvais rôle)
    // ------------------------------------------------------------------

    public function test_broadcast_does_not_fire_on_forbidden_role(): void
    {
        Event::fake([TableLayoutChanged::class]);

        $this->actingAsRole('cashier');
        $table = $this->makeTable();

        $this->patchJson("/api/tables/{$table->id}/rotate", [
            'rotation' => 90,
        ])->assertForbidden();

        Event::assertNotDispatched(TableLayoutChanged::class);
    }
}
