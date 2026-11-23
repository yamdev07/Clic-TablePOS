<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuItemCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_dish_keeps_its_description(): void
    {
        $restaurant = Restaurant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => 'manager',
        ]));

        $this->postJson('/api/menu', [
            'name' => 'Poisson braisé',
            'description' => 'Poisson entier grillé, sauce tomate pimentée',
            'price' => 4500,
        ])
            ->assertCreated()
            ->assertJsonPath('description', 'Poisson entier grillé, sauce tomate pimentée');

        $this->assertDatabaseHas('menu_items', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Poisson braisé',
            'description' => 'Poisson entier grillé, sauce tomate pimentée',
        ]);
    }
}
