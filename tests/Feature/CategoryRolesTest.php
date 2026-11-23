<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryRolesTest extends TestCase
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

    private function categoryNamed(string $name): Category
    {
        return Category::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'display_order' => 0,
            'is_active' => true,
        ]);
    }

    public function test_waiter_kitchen_and_cashier_cannot_create_rename_or_delete_a_category(): void
    {
        foreach (['waiter', 'kitchen', 'cashier'] as $role) {
            $category = $this->categoryNamed("Catégorie {$role}");
            $this->actingAsRole($role);

            $this->postJson('/api/categories', ['name' => "Nouvelle {$role}"])
                ->assertForbidden();
            $this->assertDatabaseMissing('categories', ['name' => "Nouvelle {$role}"]);

            $this->putJson("/api/categories/{$category->id}", ['name' => 'Renommée'])
                ->assertForbidden();
            $this->assertSame("Catégorie {$role}", $category->fresh()->name, "rôle {$role}");

            $this->deleteJson("/api/categories/{$category->id}")->assertForbidden();
            $this->assertNotNull(Category::find($category->id), "rôle {$role}");
        }
    }

    public function test_manager_and_admin_can_still_create_rename_and_delete_a_category(): void
    {
        foreach (['manager', 'admin'] as $role) {
            $category = $this->categoryNamed("Catégorie {$role}");
            $this->actingAsRole($role);

            $this->postJson('/api/categories', ['name' => "Nouvelle {$role}"])
                ->assertCreated();

            $this->putJson("/api/categories/{$category->id}", ['name' => "Renommée {$role}"])
                ->assertOk();
            $this->assertSame("Renommée {$role}", $category->fresh()->name, "rôle {$role}");

            $this->deleteJson("/api/categories/{$category->id}")->assertNoContent();
            $this->assertNull(Category::find($category->id), "rôle {$role}");
        }
    }
}
