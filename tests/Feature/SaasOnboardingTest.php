<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inscription d'un nouveau restaurant, période d'essai et administration plateforme.
 */
class SaasOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'restaurant_name' => 'Chez Fatou',
            'phone' => '+221770000000',
            'name' => 'Fatou Diop',
            'email' => 'fatou@example.com',
            'password' => 'Secret1234',
            'password_confirmation' => 'Secret1234',
        ], $overrides);
    }

    // ── Inscription ───────────────────────────────────────────────────────

    /** @test */
    public function signup_creates_a_new_trial_restaurant_with_its_admin(): void
    {
        Restaurant::factory()->create(); // un restaurant existant ne doit pas être réutilisé

        $response = $this->postJson('/api/register', $this->registerPayload());

        $response->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['token', 'user' => ['id', 'restaurant_id']]);

        $restaurant = Restaurant::where('email', 'fatou@example.com')->firstOrFail();
        $this->assertSame('trial', $restaurant->status);
        $this->assertSame('chez-fatou', $restaurant->slug);
        $this->assertTrue($restaurant->trial_ends_at->isFuture());
        $this->assertSame($restaurant->id, $response->json('user.restaurant_id'));
    }

    /** @test */
    public function signup_generates_unique_slugs(): void
    {
        Restaurant::factory()->create(['slug' => 'chez-fatou']);

        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $this->assertDatabaseHas('restaurants', ['email' => 'fatou@example.com', 'slug' => 'chez-fatou-2']);
    }

    /** @test */
    public function signup_requires_unique_email_and_confirmed_password(): void
    {
        User::factory()->create(['email' => 'fatou@example.com']);

        $this->postJson('/api/register', $this->registerPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/register', $this->registerPayload([
            'email' => 'autre@example.com', 'password_confirmation' => 'different',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    /** @test */
    public function new_restaurant_starts_empty_and_isolated(): void
    {
        $existing = Restaurant::factory()->create();
        Table::factory()->create(['restaurant_id' => $existing->id]);

        $token = $this->postJson('/api/register', $this->registerPayload())->json('token');

        $this->getJson('/api/tables', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonCount(0);
    }

    // ── Période d'essai ───────────────────────────────────────────────────

    /** @test */
    public function expired_trial_blocks_access(): void
    {
        $restaurant = Restaurant::factory()->create(['status' => 'trial', 'trial_ends_at' => now()->subDay()]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id, 'password' => bcrypt('password123')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');

        Sanctum::actingAs($user);
        $this->getJson('/api/tables')->assertForbidden();
    }

    /** @test */
    public function running_trial_allows_access(): void
    {
        $restaurant = Restaurant::factory()->create(['status' => 'trial', 'trial_ends_at' => now()->addDays(3)]);
        Sanctum::actingAs(User::factory()->create(['restaurant_id' => $restaurant->id]));

        $this->getJson('/api/tables')->assertOk();
    }

    // ── Administration plateforme ─────────────────────────────────────────

    private function superAdmin(): User
    {
        return User::factory()->create(['restaurant_id' => null, 'role' => 'super_admin']);
    }

    /** @test */
    public function super_admin_can_list_and_activate_restaurants(): void
    {
        $restaurant = Restaurant::factory()->create(['status' => 'trial', 'trial_ends_at' => now()->subDay()]);
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/platform/restaurants')->assertOk()
            ->assertJsonPath('data.0.id', $restaurant->id);
        $this->getJson('/api/platform/stats')->assertOk()
            ->assertJsonPath('trials_expired', 1);

        $this->patchJson("/api/platform/restaurants/{$restaurant->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active');

        $this->assertNull(User::factory()->create(['restaurant_id' => $restaurant->id])->accessDenialReason());
    }

    /** @test */
    public function super_admin_can_suspend_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        Sanctum::actingAs($this->superAdmin());

        $this->patchJson("/api/platform/restaurants/{$restaurant->id}", ['status' => 'suspended'])->assertOk();

        $this->assertNotNull($owner->fresh()->accessDenialReason());
    }

    /** @test */
    public function restaurant_admin_cannot_access_platform_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/platform/restaurants')->assertForbidden();
        $this->patchJson('/api/platform/restaurants/'.Restaurant::factory()->create()->id, ['status' => 'active'])
            ->assertForbidden();
    }

    /** @test */
    public function super_admin_cannot_use_tenant_routes(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/tables')->assertForbidden();
    }

    /** @test */
    public function super_admin_can_login_without_restaurant(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'root@clicettable.com', '--password' => 'rootpass123'])
            ->assertSuccessful();

        $this->postJson('/api/login', ['email' => 'root@clicettable.com', 'password' => 'rootpass123'])
            ->assertOk()->assertJsonPath('user.role', 'super_admin');
    }
}
