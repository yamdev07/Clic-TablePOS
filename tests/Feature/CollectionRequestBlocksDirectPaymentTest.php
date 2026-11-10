<?php

namespace Tests\Feature;

use App\Models\CollectionRequest;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionRequestBlocksDirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Commande servie avec un plat à 1000 → total 1230. */
    private function servedOrder(): Order
    {
        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $dish->id,
            'item_name' => $dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'served',
        ]);

        $order->recalculate();

        return $order->fresh();
    }

    private function pay(Order $order)
    {
        return $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ]);
    }

    public function test_direct_payment_is_refused_when_order_has_a_pending_collection_request(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $this->postJson('/api/collection-requests', [
            'order_ids' => [$order->id],
            'method' => 'cash',
        ])->assertCreated();

        $this->assertNotNull($order->fresh()->pending_collection_request_id);

        // Caissier tente l'encaissement direct → 422
        $this->actingAsRole('cashier');
        $this->pay($order)
            ->assertStatus(422)
            ->assertJson(['message' => 'Cette commande est déjà dans une demande d\'encaissement en attente.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_direct_payment_works_again_after_the_request_is_cancelled(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $id = $this->postJson('/api/collection-requests', [
            'order_ids' => [$order->id],
            'method' => 'cash',
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/collection-requests/{$id}")->assertOk();

        $this->actingAsRole('cashier');
        $this->pay($order)->assertCreated();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_direct_payment_stays_refused_after_the_request_is_validated(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $id = $this->postJson('/api/collection-requests', [
            'order_ids' => [$order->id],
            'method' => 'cash',
        ])->assertCreated()->json('id');

        // On simule la validation (l'endpoint sera livré à l'étape d)
        $request = CollectionRequest::findOrFail($id);
        $request->update(['status' => 'validated', 'validated_at' => now()]);
        // La commande reste "served" et garde son pending_collection_request_id (comportement voulu avant étape d)

        $this->actingAsRole('cashier');
        $this->pay($order)->assertStatus(422);
    }

    public function test_other_orders_of_the_restaurant_can_still_be_paid_directly(): void
    {
        $this->actingAsRole('waiter');
        $inRequest = $this->servedOrder();

        $this->postJson('/api/collection-requests', [
            'order_ids' => [$inRequest->id],
            'method' => 'cash',
        ])->assertCreated();

        // Autre commande servie, PAS dans une demande
        $free = $this->servedOrder();

        $this->actingAsRole('cashier');
        $this->pay($free)->assertCreated();

        $this->assertSame('paid', $free->fresh()->status);
        $this->assertSame('served', $inRequest->fresh()->status);
        $this->assertNotNull($inRequest->fresh()->pending_collection_request_id);
    }
}
