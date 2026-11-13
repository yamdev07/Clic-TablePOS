<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashSessionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private CashRegister $register;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->register = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Caisse principale',
            'is_active' => true,
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
            'name' => 'Test '.$role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------------
    // 1. Rôles autorisés à ouvrir
    // ------------------------------------------------------------------

    public function test_only_cashier_manager_and_admin_can_open_a_session(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->postJson('/api/cash/sessions/open', [
                'cash_register_id' => $this->register->id,
                'opening_balance' => 50000,
            ])->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->postJson('/api/cash/sessions/open', [
                'cash_register_id' => $this->register->id,
                'opening_balance' => 50000,
            ])->assertCreated();

            // Annuler la session pour tester le rôle suivant
            CashSession::query()->delete();
        }
    }

    // ------------------------------------------------------------------
    // 2. Ouverture crée une session avec fond initial
    // ------------------------------------------------------------------

    public function test_open_creates_a_session_with_opening_balance(): void
    {
        $cashier = $this->actingAsRole('cashier');

        $response = $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $this->register->id,
            'opening_balance' => 50000,
        ])->assertCreated();

        $this->assertSame('open', $response->json('status'));
        $this->assertSame(50000, $response->json('opening_balance'));
        $this->assertSame($cashier->id, $response->json('opened_by'));
        $this->assertNotNull($response->json('opened_at'));

        $this->assertDatabaseHas('cash_sessions', [
            'cash_register_id' => $this->register->id,
            'status' => 'open',
            'opening_balance' => 50000,
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Une seule session ouverte par caisse
    // ------------------------------------------------------------------

    public function test_only_one_open_session_per_register(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $this->register->id,
            'opening_balance' => 50000,
        ])->assertCreated();

        $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $this->register->id,
            'opening_balance' => 50000,
        ])->assertStatus(422);

        $this->assertSame(1, CashSession::where('cash_register_id', $this->register->id)->count());
    }

    // ------------------------------------------------------------------
    // 4. Fermeture avec comptage physique et calcul d'écart
    // ------------------------------------------------------------------

    public function test_close_a_session_computes_the_difference(): void
    {
        $this->actingAsRole('cashier');

        $id = $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $this->register->id,
            'opening_balance' => 50000,
        ])->assertCreated()->json('id');

        // Simuler 150 000 en paiements cash (via injection directe)
        \DB::table('payments')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => null, // pas de lien requis pour ce test
            'user_id' => \App\Models\User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'payment_group_id' => null,
            'amount' => 150000,
            'method' => 'cash',
            'operator' => null,
            'reference' => null,
            'transaction_data' => json_encode([]),
            'status' => 'completed',
            'cash_session_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fermer avec comptage physique : 198 500 (théorique 200 000 → écart -1500)
        $response = $this->postJson("/api/cash/sessions/{$id}/close", [
            'closing_balance' => 198500,
        ])->assertOk();

        $this->assertSame('closed', $response->json('status'));
        $this->assertSame(200000, $response->json('expected_balance'));
        $this->assertSame(198500, $response->json('closing_balance'));
        $this->assertSame(-1500, $response->json('difference'));
    }

    // ------------------------------------------------------------------
    // 5. Détail d'une session expose les infos
    // ------------------------------------------------------------------

    public function test_show_session_returns_details(): void
    {
        $this->actingAsRole('cashier');
        $id = $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $this->register->id,
            'opening_balance' => 50000,
        ])->assertCreated()->json('id');

        $data = $this->getJson("/api/cash/sessions/{$id}")->assertOk()->json();

        $this->assertSame($id, $data['id']);
        $this->assertSame('open', $data['status']);
        $this->assertSame(50000, $data['opening_balance']);
    }

    // ------------------------------------------------------------------
    // 6. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_open_is_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherRegister = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $other->id,
            'name' => 'Caisse B',
            'is_active' => true,
        ]);

        $this->actingAsRole('cashier');

        $this->postJson('/api/cash/sessions/open', [
            'cash_register_id' => $otherRegister->id,
            'opening_balance' => 50000,
        ])->assertNotFound();
    }
}
