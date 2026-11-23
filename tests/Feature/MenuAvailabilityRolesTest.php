<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuAvailabilityRolesTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
    }

    private function actingAsRole(string $role): void
    {
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]));
    }

    public function test_only_manager_and_admin_can_change_the_availability_of_a_dish(): void
    {
        foreach (['waiter', 'kitchen', 'cashier'] as $role) {
            $dish = MenuItem::factory()->create([
                'restaurant_id' => $this->restaurant->id,
                'is_available' => true,
            ]);
            $this->actingAsRole($role);

            $this->patchJson("/api/menu/{$dish->id}/availability")->assertForbidden();
            $this->assertTrue($dish->fresh()->is_available, "rôle {$role}");
        }

        foreach (['manager', 'admin'] as $role) {
            $dish = MenuItem::factory()->create([
                'restaurant_id' => $this->restaurant->id,
                'is_available' => true,
            ]);
            $this->actingAsRole($role);

            $this->patchJson("/api/menu/{$dish->id}/availability")->assertOk();
            $this->assertFalse($dish->fresh()->is_available, "rôle {$role}");
        }
    }

    public function test_a_cook_cannot_put_a_dish_back_on_sale_through_the_availability_route(): void
    {
        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'is_available' => false,
            'out_of_stock_at' => now(),
            'out_of_stock_reason' => 'Test',
        ]);
        $this->actingAsRole('kitchen');

        $this->patchJson("/api/menu/{$dish->id}/availability")->assertForbidden();

        $fresh = $dish->fresh();
        $this->assertFalse($fresh->is_available);
        $this->assertNotNull($fresh->out_of_stock_at);
        $this->assertSame('Test', $fresh->out_of_stock_reason);
    }
}
