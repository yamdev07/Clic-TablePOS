<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private CashRegister $register;

    private CashSession $session;

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
        $this->session = CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $this->register->id,
            'status' => 'open',
            'opening_balance' => 50000,
            'opened_by' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'opened_at' => now(),
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
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_authorized_roles_can_add_a_movement(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
                'type' => 'in',
                'amount' => 5000,
                'reason' => 'Apport monnaie',
            ])->assertForbidden();
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
                'type' => 'in',
                'amount' => 5000,
                'reason' => 'Apport monnaie',
            ])->assertCreated();
        }
    }

    // ------------------------------------------------------------------
    // 2. Mouvement in et out
    // ------------------------------------------------------------------

    public function test_add_in_and_out_movements(): void
    {
        $this->actingAsRole('cashier');

        $in = $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => 10000,
            'reason' => 'Apport monnaie',
        ])->assertCreated();

        $out = $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'out',
            'amount' => 5000,
            'reason' => 'Paiement livreur',
        ])->assertCreated();

        $this->assertSame('in', $in->json('type'));
        $this->assertSame(10000, $in->json('amount'));
        $this->assertSame('out', $out->json('type'));
        $this->assertSame(5000, $out->json('amount'));

        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $this->session->id,
            'type' => 'in',
            'amount' => 10000,
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $this->session->id,
            'type' => 'out',
            'amount' => 5000,
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Validation : reason obligatoire, amount > 0
    // ------------------------------------------------------------------

    public function test_movement_requires_reason_and_positive_amount(): void
    {
        $this->actingAsRole('cashier');

        // Sans reason
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Montant 0
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => 0,
            'reason' => 'Test',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Montant négatif
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => -1000,
            'reason' => 'Test',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Type invalide
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'transfer',
            'amount' => 5000,
            'reason' => 'Test',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    // ------------------------------------------------------------------
    // 4. Session fermée → 422
    // ------------------------------------------------------------------

    public function test_cannot_add_movement_to_a_closed_session(): void
    {
        $this->actingAsRole('cashier');

        $this->session->update(['status' => 'closed', 'closed_at' => now()]);

        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => 5000,
            'reason' => 'Apport monnaie',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 5. Retrait > solde théorique → 422
    // ------------------------------------------------------------------

    public function test_out_movement_cannot_exceed_expected_balance(): void
    {
        $this->actingAsRole('cashier');

        // Ouverture 50 000, aucun paiement, aucun mouvement : solde = 50 000
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'out',
            'amount' => 60000, // > 50 000
            'reason' => 'Trop',
        ])->assertStatus(422);

        // 50 000 exact : OK
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'out',
            'amount' => 50000,
            'reason' => 'Retrait total',
        ])->assertCreated();
    }

    // ------------------------------------------------------------------
    // 6. Fermeture prend en compte les mouvements
    // ------------------------------------------------------------------

    public function test_close_takes_movements_into_account(): void
    {
        $this->actingAsRole('cashier');

        // Ajouter : +10 000 (in), -5 000 (out) → solde théorique 50 000 + 10 000 - 5 000 = 55 000
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in',
            'amount' => 10000,
            'reason' => 'Apport',
        ])->assertCreated();

        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'out',
            'amount' => 5000,
            'reason' => 'Paiement livreur',
        ])->assertCreated();

        // Fermer avec 54 000 comptés : écart = 54 000 - 55 000 = -1 000
        $response = $this->postJson("/api/cash/sessions/{$this->session->id}/close", [
            'closing_balance' => 54000,
        ])->assertOk();

        $this->assertSame(55000, $response->json('expected_balance'));
        $this->assertSame(-1000, $response->json('difference'));
    }

    // ------------------------------------------------------------------
    // 7. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_movements_are_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherRegister = CashRegister::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $other->id,
            'name' => 'Caisse B',
            'is_active' => true,
        ]);
        $otherSession = CashSession::create([
            'id' => (string) Str::uuid(),
            'cash_register_id' => $otherRegister->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_by' => User::factory()->create(['restaurant_id' => $other->id])->id,
            'opened_at' => now(),
        ]);

        $this->actingAsRole('cashier');

        $this->postJson("/api/cash/sessions/{$otherSession->id}/movements", [
            'type' => 'in',
            'amount' => 5000,
            'reason' => 'Test',
        ])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 8. Liste des mouvements d'une session
    // ------------------------------------------------------------------

    public function test_list_session_movements(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'in', 'amount' => 10000, 'reason' => 'Apport',
        ])->assertCreated();
        $this->postJson("/api/cash/sessions/{$this->session->id}/movements", [
            'type' => 'out', 'amount' => 5000, 'reason' => 'Livraison',
        ])->assertCreated();

        $data = $this->getJson("/api/cash/sessions/{$this->session->id}/movements")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
    }
}
