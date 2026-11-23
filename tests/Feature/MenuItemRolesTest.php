<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuItemRolesTest extends TestCase
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

    private function dishNamed(string $name): MenuItem
    {
        return MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => $name,
            'price' => 1000,
        ]);
    }

    public function test_waiter_kitchen_and_cashier_cannot_create_edit_or_delete_a_dish(): void
    {
        foreach (['waiter', 'kitchen', 'cashier'] as $role) {
            $dish = $this->dishNamed("Plat {$role}");
            $this->actingAsRole($role);

            $this->postJson('/api/menu', ['name' => "Nouveau {$role}", 'price' => 2000])
                ->assertForbidden();
            $this->assertDatabaseMissing('menu_items', ['name' => "Nouveau {$role}"]);

            $this->putJson("/api/menu/{$dish->id}", ['name' => 'Modifié', 'price' => 1])
                ->assertForbidden();
            $this->assertSame("Plat {$role}", $dish->fresh()->name, "rôle {$role}");

            $this->deleteJson("/api/menu/{$dish->id}")->assertForbidden();
            $this->assertNotNull(MenuItem::find($dish->id), "rôle {$role}");
        }
    }

    public function test_manager_and_admin_can_still_create_edit_and_delete_a_dish(): void
    {
        foreach (['manager', 'admin'] as $role) {
            $dish = $this->dishNamed("Plat {$role}");
            $this->actingAsRole($role);

            $this->postJson('/api/menu', ['name' => "Nouveau {$role}", 'price' => 2000])
                ->assertCreated();

            $this->putJson("/api/menu/{$dish->id}", ['name' => "Modifié {$role}", 'price' => 1500])
                ->assertOk();
            $this->assertSame("Modifié {$role}", $dish->fresh()->name, "rôle {$role}");

            $this->deleteJson("/api/menu/{$dish->id}")->assertNoContent();
            $this->assertNull(MenuItem::find($dish->id), "rôle {$role}");
        }
    }
}
