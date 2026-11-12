<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableModelTest extends TestCase
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

    private function makeTable(?Restaurant $restaurant = null, ?string $number = null): Table
    {
        return Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'number' => $number ?? Str::random(6),
            'capacity' => 4,
            'status' => 'free',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
    }

    private function updateModel(Table $table, array $payload)
    {
        return $this->patchJson("/api/tables/{$table->id}/model", $payload);
    }

    // ------------------------------------------------------------------
    // 1. Admin peut changer le modèle
    // ------------------------------------------------------------------

    public function test_admin_can_change_a_table_model(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        $this->assertNull($table->fresh()->shape);
        $this->assertNull($table->fresh()->size_label);

        $this->updateModel($table, [
            'shape' => 'round',
            'size_label' => '2p',
        ])->assertOk();

        $fresh = $table->fresh();
        $this->assertSame('round', $fresh->shape);
        $this->assertSame('2p', $fresh->size_label);
    }

    // ------------------------------------------------------------------
    // 2. Manager peut changer le modèle
    // ------------------------------------------------------------------

    public function test_manager_can_change_a_table_model(): void
    {
        $this->actingAsRole('manager');
        $table = $this->makeTable();

        $this->updateModel($table, [
            'shape' => 'square',
            'size_label' => '4p',
        ])->assertOk();

        $this->assertSame('square', $table->fresh()->shape);
    }

    // ------------------------------------------------------------------
    // 3. Waiter, cashier, kitchen → 403
    // ------------------------------------------------------------------

    public function test_only_admin_and_manager_can_change_the_model(): void
    {
        foreach (['waiter', 'cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $table = $this->makeTable();

            $this->updateModel($table, [
                'shape' => 'round',
                'size_label' => '2p',
            ])->assertForbidden();

            $this->assertNull($table->fresh()->shape);
        }
    }

    // ------------------------------------------------------------------
    // 4. Valeurs de shape invalides
    // ------------------------------------------------------------------

    public function test_invalid_shape_is_refused(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        $this->updateModel($table, [
            'shape' => 'triangle',
            'size_label' => '2p',
        ])->assertStatus(422);

        $this->updateModel($table, [
            'shape' => '',
            'size_label' => '2p',
        ])->assertStatus(422);

        $this->assertNull($table->fresh()->shape);
    }

    // ------------------------------------------------------------------
    // 5. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_model_change_is_isolated_across_restaurants(): void
    {
        $this->actingAsRole('admin');

        $other = Restaurant::factory()->create();
        $foreign = $this->makeTable($other);

        $this->updateModel($foreign, [
            'shape' => 'round',
            'size_label' => '2p',
        ])->assertNotFound();

        $this->assertNull($foreign->fresh()->shape);
    }

    // ------------------------------------------------------------------
    // 6. Formes valides acceptées : round, square, rectangle, banquette, oval
    // ------------------------------------------------------------------

    public function test_all_documented_shapes_are_accepted(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        foreach (['round', 'square', 'rectangle', 'banquette', 'oval'] as $shape) {
            $this->updateModel($table, [
                'shape' => $shape,
                'size_label' => '2p',
            ])->assertOk();

            $this->assertSame($shape, $table->fresh()->shape);
        }
    }
}
