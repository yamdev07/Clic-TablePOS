<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fiche restaurant (en-tête des factures) et création des comptes caissier. */
class RestaurantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Clic&Table Café']);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @test */
    public function any_staff_member_can_read_restaurant_details(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/restaurant')->assertOk()
            ->assertJsonPath('name', 'Clic&Table Café')
            ->assertJsonMissingPath('settings');
    }

    /** @test */
    public function admin_can_update_restaurant_details(): void
    {
        $this->actingAsRole('admin');

        $this->putJson('/api/restaurant', [
            'name'    => 'Cactus Hôtel',
            'email'   => 'contact@cactushotel.com',
            'phone'   => '+229 00 00 00 00',
            'address' => 'Cotonou, Bénin',
        ])->assertOk()->assertJsonPath('name', 'Cactus Hôtel');

        $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id, 'address' => 'Cotonou, Bénin']);
    }

    /** @test */
    public function non_admin_cannot_update_restaurant_details(): void
    {
        $this->actingAsRole('manager');

        $this->putJson('/api/restaurant', ['name' => 'X', 'email' => 'x@x.com'])->assertForbidden();
    }

    /** @test */
    public function restaurant_email_must_stay_unique(): void
    {
        Restaurant::factory()->create(['email' => 'pris@example.com']);
        $this->actingAsRole('admin');

        $this->putJson('/api/restaurant', ['name' => 'A', 'email' => 'pris@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    /** @test */
    public function admin_can_create_a_cashier_account(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Awa Caisse', 'email' => 'caisse@example.com', 'password' => 'secret123', 'role' => 'cashier',
        ])->assertCreated()->assertJsonPath('role', 'cashier');
    }

    /** @test */
    public function admin_cannot_create_a_platform_account(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Hack', 'email' => 'hack@example.com', 'password' => 'secret123', 'role' => 'super_admin',
        ])->assertUnprocessable();
    }
}
