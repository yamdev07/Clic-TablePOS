<?php

// tests/Feature/AuthTest.php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'email' => 'test@restaurant.com',
            'status' => 'active',
        ]);
    }

    /** @test */
    public function user_can_login_with_correct_credentials()
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role'],
                'token',
            ]);
    }

    /** @test */
    public function user_cannot_login_with_wrong_password()
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Identifiants incorrects']);
    }

    /** @test */
    public function authenticated_user_can_access_protected_route()
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJson(['id' => $user->id]);
    }

    /** @test */
    public function deactivated_user_cannot_login()
    {
        User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'email' => 'off@example.com',
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/login', ['email' => 'off@example.com', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJson(['code' => 'account_inactive']);
    }

    /** @test */
    public function user_of_suspended_restaurant_cannot_login()
    {
        $this->restaurant->update(['status' => 'suspended']);
        User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'email' => 'susp@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/login', ['email' => 'susp@example.com', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJson(['code' => 'account_inactive']);
    }

    /** @test */
    public function existing_token_is_rejected_once_user_is_deactivated()
    {
        $user = User::factory()->create(['restaurant_id' => $this->restaurant->id]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];

        $this->getJson('/api/me', $headers)->assertOk();

        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me', $headers)->assertStatus(403);
        $this->postJson('/api/logout', [], $headers)->assertOk();
    }

    /** @test */
    public function login_validation_errors_return_422()
    {
        $this->postJson('/api/login', ['email' => 'not-an-email'])->assertStatus(422);
    }
}
