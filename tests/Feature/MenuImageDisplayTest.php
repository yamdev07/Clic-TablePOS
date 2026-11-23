<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuImageDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_list_exposes_the_image_url_of_each_dish(): void
    {
        Storage::fake('public');

        $restaurant = Restaurant::factory()->create();
        $category = Category::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Entrées',
            'slug' => 'entrees-'.Str::random(4),
            'display_order' => 0,
            'is_active' => true,
        ]);

        $path = "menu-items/{$restaurant->id}/poisson.jpg";
        $withImage = MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_active' => true,
            'image' => $path,
        ]);
        $withoutImage = MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_active' => true,
            'image' => null,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => 'waiter',
        ]));

        $response = $this->getJson('/api/menu')->assertOk();

        $items = collect($response->json())
            ->flatMap(fn ($cat) => $cat['menu_items'])
            ->keyBy('id');

        $this->assertNotNull($items[$withImage->id]['image_url'] ?? null);
        $this->assertStringEndsWith($path, $items[$withImage->id]['image_url']);
        $this->assertNull($items[$withoutImage->id]['image_url'] ?? null);
    }
}
