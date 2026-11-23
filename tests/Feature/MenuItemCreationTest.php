<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuItemCreationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'manager',
        ]));
    }

    public function test_a_new_dish_keeps_its_description(): void
    {
        $this->postJson('/api/menu', [
            'name' => 'Poisson braisé',
            'description' => 'Poisson entier grillé, sauce tomate pimentée',
            'price' => 4500,
        ])
            ->assertCreated()
            ->assertJsonPath('description', 'Poisson entier grillé, sauce tomate pimentée');

        $this->assertDatabaseHas('menu_items', [
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Poisson braisé',
            'description' => 'Poisson entier grillé, sauce tomate pimentée',
        ]);
    }

    public function test_a_new_dish_can_be_created_with_an_image(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/menu', [
            'name' => 'Poisson braisé',
            'price' => 4500,
            'image' => UploadedFile::fake()->image('poisson.jpg', 600, 400),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('image');

        $this->assertNotNull($path);
        $this->assertStringStartsWith("menu-items/{$this->restaurant->id}/", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('menu_items', [
            'name' => 'Poisson braisé',
            'image' => $path,
        ]);
    }

    public function test_a_dish_image_must_be_a_real_image_of_reasonable_size(): void
    {
        Storage::fake('public');

        $this->post('/api/menu', [
            'name' => 'Faux fichier',
            'price' => 1000,
            'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
        $this->assertDatabaseMissing('menu_items', ['name' => 'Faux fichier']);

        $this->post('/api/menu', [
            'name' => 'Image trop lourde',
            'price' => 1000,
            'image' => UploadedFile::fake()->image('gros.jpg')->size(3000),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
        $this->assertDatabaseMissing('menu_items', ['name' => 'Image trop lourde']);
    }
}
