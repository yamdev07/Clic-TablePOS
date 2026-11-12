<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableRotationTest extends TestCase
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

    private function rotate(Table $table, int $rotation)
    {
        return $this->patchJson("/api/tables/{$table->id}/rotate", [
            'rotation' => $rotation,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Admin peut pivoter
    // ------------------------------------------------------------------

    public function test_admin_can_rotate_a_table(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        $this->assertSame(0, $table->fresh()->rotation);

        $this->rotate($table, 90)->assertOk();

        $this->assertSame(90, $table->fresh()->rotation);
    }

    // ------------------------------------------------------------------
    // 2. Manager peut pivoter
    // ------------------------------------------------------------------

    public function test_manager_can_rotate_a_table(): void
    {
        $this->actingAsRole('manager');
        $table = $this->makeTable();

        $this->rotate($table, 90)->assertOk();

        $this->assertSame(90, $table->fresh()->rotation);
    }

    // ------------------------------------------------------------------
    // 3. Waiter peut pivoter (règle spéciale)
    // ------------------------------------------------------------------

    public function test_waiter_can_rotate_a_table(): void
    {
        $this->actingAsRole('waiter');
        $table = $this->makeTable();

        $this->rotate($table, 90)->assertOk();

        $this->assertSame(90, $table->fresh()->rotation);
    }

    // ------------------------------------------------------------------
    // 4. Cashier et kitchen ne peuvent PAS pivoter
    // ------------------------------------------------------------------

    public function test_cashier_and_kitchen_cannot_rotate_a_table(): void
    {
        foreach (['cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $table = $this->makeTable();

            $this->rotate($table, 90)->assertForbidden();

            $this->assertSame(0, $table->fresh()->rotation);
        }
    }

    // ------------------------------------------------------------------
    // 5. Valeur de rotation invalide
    // ------------------------------------------------------------------

    public function test_invalid_rotation_value_is_refused(): void
    {
        $this->actingAsRole('admin');
        $table = $this->makeTable();

        foreach ([45, 180, -90, 'abc', 270] as $invalid) {
            $this->rotate($table, (int) $invalid)->assertStatus(422);
        }

        $this->assertSame(0, $table->fresh()->rotation);
    }

    // ------------------------------------------------------------------
    // 6. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_rotation_is_isolated_across_restaurants(): void
    {
        $this->actingAsRole('admin');

        $other = Restaurant::factory()->create();
        $foreign = $this->makeTable($other);

        $this->rotate($foreign, 90)->assertNotFound();

        $this->assertSame(0, $foreign->fresh()->rotation);
    }
}
