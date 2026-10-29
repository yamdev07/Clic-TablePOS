<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'restaurant_name' => 'Chez Koffi',
        'name'            => 'Koffi Admin',
        'email'           => 'koffi@test.com',
        'password'        => 'secret123',
    ];

    public function test_register_creates_a_new_restaurant_with_an_admin(): void
    {
        $existing = Restaurant::factory()->create();
        User::factory()->count(2)->create(['restaurant_id' => $existing->id]);

        $response = $this->postJson('/api/register', $this->payload);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.restaurant.name', 'Chez Koffi');

        $newUser = User::where('email', 'koffi@test.com')->firstOrFail();

        // Un nouveau restaurant a été créé, distinct de l'existant
        $this->assertSame(2, Restaurant::count());
        $this->assertNotSame($existing->id, $newUser->restaurant_id);

        // Le restaurant existant n'a pas reçu de nouvel utilisateur
        $this->assertSame(2, User::where('restaurant_id', $existing->id)->count());
    }

    public function test_new_admin_cannot_see_data_of_existing_restaurant(): void
    {
        $existing = Restaurant::factory()->create();
        $existingTable = Table::factory()->create(['restaurant_id' => $existing->id]);

        $token = $this->postJson('/api/register', $this->payload)->json('token');

        $tables = $this->withToken($token)->getJson('/api/tables');

        $tables->assertOk();
        $this->assertFalse(collect($tables->json())->pluck('id')->contains($existingTable->id));
    }

    public function test_two_restaurants_can_share_the_same_name(): void
    {
        $this->postJson('/api/register', $this->payload)->assertCreated();

        $this->postJson('/api/register', array_merge($this->payload, [
            'email' => 'autre@test.com',
        ]))->assertCreated();

        $this->assertSame(2, Restaurant::count());
        $this->assertSame(2, Restaurant::pluck('slug')->unique()->count());
    }

    public function test_register_requires_restaurant_name(): void
    {
        $payload = $this->payload;
        unset($payload['restaurant_name']);

        $this->postJson('/api/register', $payload)->assertStatus(422);

        $this->assertSame(0, User::count());
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->postJson('/api/register', $this->payload)->assertCreated();

        $this->postJson('/api/register', $this->payload)->assertStatus(422);

        $this->assertSame(1, Restaurant::count());
    }
}
