<?php

namespace Tests\Feature;

use App\Models\CollectionRequest;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentNumber;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);
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

    /** Commande servie, 1 plat à 1000 → total 1230. */
    private function servedOrder(?Table $table = null): Order
    {
        $table ??= $this->table;

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'served',
        ]);

        $order->recalculate();

        return $order->fresh();
    }

    private function createRequest(array $orderIds, string $method = 'cash', ?string $operator = null): CollectionRequest
    {
        $payload = ['order_ids' => $orderIds, 'method' => $method];
        if ($operator) {
            $payload['operator'] = $operator;
        }
        $id = $this->postJson('/api/collection-requests', $payload)
            ->assertCreated()
            ->json('id');

        return CollectionRequest::findOrFail($id);
    }

    private function validateRequest(CollectionRequest $cr, array $payload = [])
    {
        return $this->postJson("/api/collection-requests/{$cr->id}/validate", array_merge([
            'receipt_confirmed' => true,
        ], $payload));
    }

    private function seedMtnNumber(): PaymentNumber
    {
        return PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'mtn_momo',
            'phone_number' => '+22997000001',
            'beneficiary_name' => 'Caisse A',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_cashier_manager_and_admin_can_validate(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $this->actingAsRole('waiter');
            $cr = $this->createRequest([$this->servedOrder()->id]);

            $this->actingAsRole($role);
            $this->validateRequest($cr)->assertForbidden();
            $this->assertSame('pending', $cr->fresh()->status);
            $this->assertDatabaseCount('payments', 0);
        }

        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAsRole('waiter');
            $cr = $this->createRequest([$this->servedOrder()->id]);

            $this->actingAsRole($role);
            $this->validateRequest($cr)->assertOk();
            $this->assertSame('validated', $cr->fresh()->status);
        }
    }

    // ------------------------------------------------------------------
    // 2. Confirmation obligatoire
    // ------------------------------------------------------------------

    public function test_a_collection_needs_the_cashier_confirmation(): void
    {
        $this->actingAsRole('waiter');
        $cr = $this->createRequest([$this->servedOrder()->id]);

        $this->actingAsRole('cashier');

        $this->postJson("/api/collection-requests/{$cr->id}/validate", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt_confirmed');

        $this->assertSame('pending', $cr->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    // ------------------------------------------------------------------
    // 3. Statuts
    // ------------------------------------------------------------------

    public function test_a_cancelled_or_already_validated_request_cannot_be_validated(): void
    {
        $this->actingAsRole('waiter');

        // cas annulée
        $cr1 = $this->createRequest([$this->servedOrder()->id]);
        $this->deleteJson("/api/collection-requests/{$cr1->id}")->assertOk();

        $this->actingAsRole('cashier');
        $this->validateRequest($cr1)->assertStatus(422);
        $this->assertSame('cancelled', $cr1->fresh()->status);
        $this->assertDatabaseCount('payments', 0);

        // cas déjà validée
        $this->actingAsRole('waiter');
        $cr2 = $this->createRequest([$this->servedOrder()->id]);

        $this->actingAsRole('cashier');
        $this->validateRequest($cr2)->assertOk();
        $this->validateRequest($cr2)->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 4. Validation cash : N payments, groupe commun, tout payé
    // ------------------------------------------------------------------

    public function test_cash_validation_creates_one_payment_per_order_and_marks_all_paid(): void
    {
        $this->actingAsRole('waiter');
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();
        $cr = $this->createRequest([$orderA->id, $orderB->id]);

        $cashier = $this->actingAsRole('cashier');
        $this->validateRequest($cr)->assertOk();

        $fresh = $cr->fresh();
        $this->assertSame('validated', $fresh->status);
        $this->assertSame($cashier->id, $fresh->validated_by);
        $this->assertNotNull($fresh->validated_at);

        $this->assertSame(2, Payment::where('payment_group_id', '!=', null)->count());
        $groupIds = Payment::whereNotNull('payment_group_id')->pluck('payment_group_id')->unique();
        $this->assertCount(1, $groupIds);
        $this->assertCount(2, Payment::where('payment_group_id', $groupIds->first())->get());

        // Un Payment par commande, montant complet, statut completed
        foreach ([$orderA, $orderB] as $order) {
            $payment = Payment::where('order_id', $order->id)->first();
            $this->assertNotNull($payment);
            $this->assertSame('completed', $payment->status);
            $this->assertSame($order->total, $payment->amount);
            $this->assertSame('cash', $payment->method);
            $this->assertNull($payment->reference);
            $this->assertNull($payment->operator);

            $this->assertSame('paid', $order->fresh()->status);
            $this->assertNull($order->fresh()->pending_collection_request_id);
        }
    }

    // ------------------------------------------------------------------
    // 5. Validation Mobile Money : référence partagée dans le groupe
    // ------------------------------------------------------------------

    public function test_mobile_money_validation_shares_the_same_reference_across_the_group(): void
    {
        $this->seedMtnNumber();

        $this->actingAsRole('waiter');
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();
        $cr = $this->createRequest([$orderA->id, $orderB->id], 'mobile_money', 'mtn_momo');

        $this->actingAsRole('cashier');
        $this->validateRequest($cr, ['reference' => 'MOMO-REF-1'])->assertOk();

        $payments = Payment::whereNotNull('payment_group_id')->get();
        $this->assertCount(2, $payments);
        $this->assertCount(1, $payments->pluck('payment_group_id')->unique());
        foreach ($payments as $p) {
            $this->assertSame('MOMO-REF-1', $p->reference);
            $this->assertSame('mtn_momo', $p->operator);
            $this->assertSame('mobile_money', $p->method);
        }
    }

    // ------------------------------------------------------------------
    // 6. Référence MM : un autre groupe ne peut pas la réutiliser
    // ------------------------------------------------------------------

    public function test_mobile_money_reference_cannot_be_reused_by_another_group(): void
    {
        $this->seedMtnNumber();
        $this->actingAsRole('waiter');

        // Groupe 1
        $cr1 = $this->createRequest([$this->servedOrder()->id], 'mobile_money', 'mtn_momo');
        $this->actingAsRole('cashier');
        $this->validateRequest($cr1, ['reference' => 'MOMO-REF-1'])->assertOk();

        // Groupe 2 avec la même référence → 422
        $this->actingAsRole('waiter');
        $cr2 = $this->createRequest([$this->servedOrder()->id], 'mobile_money', 'mtn_momo');
        $this->actingAsRole('cashier');
        $this->validateRequest($cr2, ['reference' => 'MOMO-REF-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');

        $this->assertSame('pending', $cr2->fresh()->status);
        $this->assertSame(1, Payment::where('reference', 'MOMO-REF-1')->count());
        $group1Payments = Payment::where('reference', 'MOMO-REF-1')->get();
        $this->assertCount(1, $group1Payments);
        $group1GroupId = $group1Payments->first()->payment_group_id;
        $this->assertNotNull($group1GroupId);
        // Aucun nouveau payment n'a été ajouté par la tentative du groupe 2
        $this->assertSame(1, Payment::where('payment_group_id', $group1GroupId)->count());
    }

    // ------------------------------------------------------------------
    // 7. Atomicité : une commande annulée bloque toute la validation
    // ------------------------------------------------------------------

    public function test_validation_is_atomic_when_one_order_is_paid_or_cancelled(): void
    {
        $this->actingAsRole('waiter');
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();
        $cr = $this->createRequest([$orderA->id, $orderB->id]);

        // On annule orderB entre-temps
        $orderB->update(['status' => 'cancelled']);

        $this->actingAsRole('cashier');
        $this->validateRequest($cr)->assertStatus(422);

        $this->assertSame('pending', $cr->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('served', $orderA->fresh()->status);
        $this->assertSame('cancelled', $orderB->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 8. Paiement partiel direct préexistant → refus
    // ------------------------------------------------------------------

    public function test_validation_refuses_a_partial_direct_payment(): void
    {
        $this->actingAsRole('waiter');
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();

        // On simule un paiement partiel direct sur orderA (500 < 1230)
        Payment::create([
            'id' => (string) Str::uuid(),
            'order_id' => $orderA->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'payment_group_id' => null,
            'amount' => 500,
            'method' => 'cash',
            'operator' => null,
            'reference' => null,
            'transaction_data' => ['receipt_confirmed_at' => now()->toIso8601String()],
            'status' => 'completed',
        ]);
        $orderA->recalculate();

        $cr = $this->createRequest([$orderA->id, $orderB->id]);

        $this->actingAsRole('cashier');
        $this->validateRequest($cr)
            ->assertStatus(422)
            ->assertJson(['message' => 'Une commande de cette demande possède déjà un paiement partiel. Veuillez la régler séparément.']);

        $this->assertSame('pending', $cr->fresh()->status);
        $this->assertDatabaseCount('payments', 1); // seule la ligne préexistante
        $this->assertSame('served', $orderB->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 9. Isolation
    // ------------------------------------------------------------------

    public function test_validation_is_isolated_across_restaurants(): void
    {
        $this->actingAsRole('waiter');
        $cr = $this->createRequest([$this->servedOrder()->id]);

        $otherRestaurant = Restaurant::factory()->create();
        $this->actingAsRole('cashier', $otherRestaurant);

        $this->validateRequest($cr)->assertNotFound();
        $this->assertSame('pending', $cr->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    // ------------------------------------------------------------------
    // 10. Après validation, la commande ne peut plus être payée en direct
    // ------------------------------------------------------------------

    public function test_a_validated_request_cannot_be_paid_directly_afterwards(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();
        $cr = $this->createRequest([$order->id]);

        $this->actingAsRole('cashier');
        $this->validateRequest($cr)->assertOk();

        // La commande est paid → un paiement direct doit être refusé
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => 100, 'method' => 'cash', 'receipt_confirmed' => true,
        ])->assertStatus(422);

        // On garde une seule ligne Payment
        $this->assertDatabaseCount('payments', 1);
    }
}
