<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashRegisterCrudTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
    }

    private function actingAsRole(string $role, ?Restaurant $restaurant = null): User
    {
        $user = User::factory()->create([
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_authorized_roles_can_list_and_create_registers(): void
    {
        foreach (['waiter', 'cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/cash/registers')->assertForbidden();
            $this->postJson('/api/cash/registers', ['name' => 'Caisse test'])->assertForbidden();
        }

        foreach (['admin', 'manager'] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/cash/registers')->assertOk();
            $this->postJson('/api/cash/registers', ['name' => 'Caisse '.$role])->assertCreated();

            CashRegister::query()->delete();
        }
    }

    // ------------------------------------------------------------------
    // 2. Création
    // ------------------------------------------------------------------

    public function test_admin_can_create_a_register(): void
    {
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/cash/registers', [
            'name' => 'Caisse bar',
        ])->assertCreated();

        $this->assertSame('Caisse bar', $response->json('name'));
        $this->assertTrue($response->json('is_active'));

        $this->assertDatabaseHas('cash_registers', [
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse bar',
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Liste (isolation)
    // ------------------------------------------------------------------

    public function test_list_is_isolated_across_restaurants(): void
    {
        CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse A',
            'is_active' => true,
        ]);

        $other = Restaurant::factory()->create();
        CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $other->id,
            'name' => 'Caisse B',
            'is_active' => true,
        ]);

        $this->actingAsRole('admin');
        $data = $this->getJson('/api/cash/registers')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Caisse A', $data[0]['name']);
    }

    // ------------------------------------------------------------------
    // 4. Mise à jour
    // ------------------------------------------------------------------

    public function test_admin_can_update_a_register(): void
    {
        $this->actingAsRole('admin');

        $register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Ancien nom',
            'is_active' => true,
        ]);

        $this->patchJson("/api/cash/registers/{$register->id}", [
            'name' => 'Nouveau nom',
            'is_active' => false,
        ])->assertOk();

        $fresh = $register->fresh();
        $this->assertSame('Nouveau nom', $fresh->name);
        $this->assertFalse($fresh->is_active);
    }

    // ------------------------------------------------------------------
    // 5. Suppression interdite si sessions existantes
    // ------------------------------------------------------------------

    public function test_cannot_delete_a_register_with_sessions(): void
    {
        $this->actingAsRole('admin');

        $register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse avec historique',
            'is_active' => true,
        ]);

        // Créer une session liée
        \App\Models\CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $register->id,
            'status' => 'closed',
            'opening_balance' => 0,
            'opened_by' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'opened_at' => now(),
        ]);

        $this->deleteJson("/api/cash/registers/{$register->id}")->assertStatus(422);

        $this->assertDatabaseHas('cash_registers', ['id' => $register->id]);
    }

    // ------------------------------------------------------------------
    // 6. Suppression autorisée sans session
    // ------------------------------------------------------------------

    public function test_can_delete_a_register_without_sessions(): void
    {
        $this->actingAsRole('admin');

        $register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse sans historique',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/cash/registers/{$register->id}")->assertNoContent();

        $this->assertDatabaseMissing('cash_registers', ['id' => $register->id]);
    }

    // ------------------------------------------------------------------
    // 7. Isolation (404 sur caisse d'un autre restaurant)
    // ------------------------------------------------------------------

    public function test_cannot_update_a_register_of_another_restaurant(): void
    {
        $other = Restaurant::factory()->create();
        $foreign = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $other->id,
            'name' => 'Caisse B',
            'is_active' => true,
        ]);

        $this->actingAsRole('admin');

        $this->patchJson("/api/cash/registers/{$foreign->id}", ['name' => 'Pirate'])->assertNotFound();
        $this->deleteJson("/api/cash/registers/{$foreign->id}")->assertNotFound();
    }
}
