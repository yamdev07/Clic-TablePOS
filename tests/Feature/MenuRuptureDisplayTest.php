<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuRuptureDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->category = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plats',
            'slug' => 'plats',
        ]);
    }

    /** Lit GET /api/menu avec le rôle donné et renvoie les données du plat. */
    private function dishFromMenu(string $role, MenuItem $dish): array
    {
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]));

        $data = $this->getJson('/api/menu')->assertOk()->json();
        $found = collect($data)
            ->flatMap(fn ($category) => $category['menu_items'])
            ->firstWhere('id', $dish->id);

        $this->assertNotNull($found);

        return $found;
    }

    public function test_the_menu_exposes_the_rupture_details_with_the_author_name_only(): void
    {
        $author = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);
        $declaredAt = now()->subMinutes(10)->startOfSecond();
        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
            'is_available' => false,
            'out_of_stock_at' => $declaredAt,
            'out_of_stock_reason' => 'Plus de thon frais',
            'out_of_stock_by' => $author->id,
        ]);

        $found = $this->dishFromMenu('waiter', $dish);

        $this->assertFalse($found['is_available']);
        $this->assertSame('Plus de thon frais', $found['out_of_stock_reason']);
        $this->assertSame($declaredAt->toISOString(), $found['out_of_stock_at']);
        $this->assertSame($author->id, $found['out_of_stock_by']['id']);
        $this->assertSame($author->name, $found['out_of_stock_by']['name']);
        $this->assertArrayNotHasKey('email', $found['out_of_stock_by']);
        $this->assertArrayNotHasKey('phone', $found['out_of_stock_by']);
    }

    public function test_a_dish_without_rupture_has_empty_rupture_fields(): void
    {
        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
            'is_available' => true,
        ]);

        $found = $this->dishFromMenu('kitchen', $dish);

        $this->assertTrue($found['is_available']);
        foreach (['out_of_stock_at', 'out_of_stock_reason', 'out_of_stock_by'] as $key) {
            $this->assertArrayHasKey($key, $found);
            $this->assertNull($found[$key], $key);
        }
    }
}
