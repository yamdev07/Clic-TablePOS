<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $restaurant = Restaurant::factory()->create();

        return User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => $role,
        ]);
    }

    public function test_sans_token_renvoie_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_admin_et_gerant_ont_acces(): void
    {
        foreach (['admin', 'manager'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));

            $this->getJson('/api/dashboard')->assertOk();
        }
    }

    public function test_serveur_et_cuisine_recoivent_403(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));

            $this->getJson('/api/dashboard')->assertForbidden();
        }
    }
}
