<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
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

    private function makeTable(?Restaurant $restaurant = null, string $number = '1'): Table
    {
        return Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'number' => $number,
            'capacity' => 4,
            'status' => 'free',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Admin peut mettre à jour le layout en bulk
    // ------------------------------------------------------------------

    public function test_admin_can_update_the_layout_in_bulk(): void
    {
        $this->actingAsRole('admin');

        $t1 = $this->makeTable(null, '1');
        $t2 = $this->makeTable(null, '2');
        $t3 = $this->makeTable(null, '3');

        $this->putJson('/api/tables/layout', [
            'tables' => [
                ['id' => $t1->id, 'x_position' => 100, 'y_position' => 200],
                ['id' => $t2->id, 'x_position' => 300, 'y_position' => 400],
                ['id' => $t3->id, 'x_position' => 500, 'y_position' => 600],
            ],
        ])->assertOk();

        $this->assertSame([100, 200], [$t1->fresh()->x_position, $t1->fresh()->y_position]);
        $this->assertSame([300, 400], [$t2->fresh()->x_position, $t2->fresh()->y_position]);
        $this->assertSame([500, 600], [$t3->fresh()->x_position, $t3->fresh()->y_position]);
    }

    // ------------------------------------------------------------------
    // 2. Rôles interdits
    // ------------------------------------------------------------------

    public function test_only_admin_and_manager_can_update_the_layout(): void
    {
        foreach (['waiter', 'cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $table = $this->makeTable(null, Str::random(6));

            $this->putJson('/api/tables/layout', [
                'tables' => [['id' => $table->id, 'x_position' => 100, 'y_position' => 200]],
            ])->assertForbidden();
        }

        // manager autorisé
        $this->actingAsRole('manager');
        $table = $this->makeTable();
        $this->putJson('/api/tables/layout', [
            'tables' => [['id' => $table->id, 'x_position' => 100, 'y_position' => 200]],
        ])->assertOk();
    }

    // ------------------------------------------------------------------
    // 3. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_tables_of_another_restaurant_are_refused(): void
    {
        $this->actingAsRole('admin');

        $other = Restaurant::factory()->create();
        $foreignTable = $this->makeTable($other, '1');

        $this->putJson('/api/tables/layout', [
            'tables' => [['id' => $foreignTable->id, 'x_position' => 100, 'y_position' => 200]],
        ])->assertStatus(422);

        $this->assertSame(0, $foreignTable->fresh()->x_position);
    }

    // ------------------------------------------------------------------
    // 4. Validation des positions
    // ------------------------------------------------------------------

    public function test_positions_must_be_within_valid_bounds(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        // Négatif
        $this->putJson('/api/tables/layout', [
            'tables' => [['id' => $table->id, 'x_position' => -10, 'y_position' => 200]],
        ])->assertStatus(422);

        // > 2000
        $this->putJson('/api/tables/layout', [
            'tables' => [['id' => $table->id, 'x_position' => 100, 'y_position' => 5000]],
        ])->assertStatus(422);

        // Non entier
        $this->putJson('/api/tables/layout', [
            'tables' => [['id' => $table->id, 'x_position' => 'abc', 'y_position' => 200]],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 5. Transaction : tout ou rien
    // ------------------------------------------------------------------

    public function test_layout_update_is_atomic(): void
    {
        $this->actingAsRole('admin');

        $t1 = $this->makeTable(null, '1');
        $other = Restaurant::factory()->create();
        $foreign = $this->makeTable($other, '2');

        // Payload avec 1 valide + 1 invalide (autre restaurant)
        $this->putJson('/api/tables/layout', [
            'tables' => [
                ['id' => $t1->id, 'x_position' => 100, 'y_position' => 200],
                ['id' => $foreign->id, 'x_position' => 300, 'y_position' => 400],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, $t1->fresh()->x_position);
    }

    // ------------------------------------------------------------------
    // 6. GET /api/tables/layout retourne toutes les tables avec positions
    // ------------------------------------------------------------------

    public function test_layout_endpoint_returns_all_tables_with_positions(): void
    {
        $this->actingAsRole('waiter');

        $t1 = $this->makeTable(null, '1');
        $t2 = $this->makeTable(null, '2');

        $t1->update(['x_position' => 100, 'y_position' => 200]);
        $t2->update(['x_position' => 300, 'y_position' => 400]);

        $data = $this->getJson('/api/tables/layout')->assertOk()->json();

        $this->assertCount(2, $data);
        $this->assertSame($t1->id, $data[0]['id']);
        $this->assertSame(100, $data[0]['x_position']);
        $this->assertSame(200, $data[0]['y_position']);
    }
}
