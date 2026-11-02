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
        'name' => 'Koffi Admin',
        'email' => 'koffi@test.com',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
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

    public function test_register_requires_matching_password_confirmation(): void
    {
        $this->postJson('/api/register', array_merge($this->payload, [
            'password_confirmation' => 'Different123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_register_rejects_weak_passwords(): void
    {
        foreach (['Court1', 'toutminuscule1', 'TOUTMAJUSCULE1', 'SansChiffres'] as $weak) {
            $this->postJson('/api/register', array_merge($this->payload, [
                'password' => $weak,
                'password_confirmation' => $weak,
            ]))->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->assertSame(0, User::count());
    }

    public function test_new_restaurant_uses_benin_timezone(): void
    {
        $this->postJson('/api/register', $this->payload)->assertCreated();

        $this->assertSame('Africa/Porto-Novo', Restaurant::firstOrFail()->timezone);
    }

    public function test_register_accepts_a_custom_timezone(): void
    {
        $this->postJson('/api/register', array_merge($this->payload, [
            'timezone' => 'Africa/Dakar',
        ]))->assertCreated();

        $this->assertSame('Africa/Dakar', Restaurant::firstOrFail()->timezone);
    }

    public function test_register_rejects_an_invalid_timezone(): void
    {
        $this->postJson('/api/register', array_merge($this->payload, [
            'timezone' => 'Mars/Olympus',
        ]))->assertStatus(422)->assertJsonValidationErrors('timezone');
    }
}
