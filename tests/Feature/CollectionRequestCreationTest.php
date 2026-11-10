<?php

namespace Tests\Feature;

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

class CollectionRequestCreationTest extends TestCase
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

    /** Commande servie avec un plat de 1000 : total 1230 (1000 + 18 % + 5 %). */
    private function servedOrder(?Restaurant $restaurant = null, ?Table $table = null): Order
    {
        $restaurant ??= $this->restaurant;
        $table ??= $this->table;

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
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

    private function createRequest(array $payload)
    {
        return $this->postJson('/api/collection-requests', $payload);
    }

    // ------------------------------------------------------------------
    // 1. Création par rôle
    // ------------------------------------------------------------------

    public function test_only_waiter_manager_and_admin_can_create_a_collection_request(): void
    {
        // refus : cashier, kitchen
        foreach (['cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $order = $this->servedOrder();

            $this->createRequest([
                'order_ids' => [$order->id],
                'method' => 'cash',
            ])->assertForbidden();

            $this->assertDatabaseCount('collection_requests', 0);
        }

        // acceptation : waiter, manager, admin
        foreach (['waiter', 'manager', 'admin'] as $role) {
            $user = $this->actingAsRole($role);
            $order = $this->servedOrder();

            $response = $this->createRequest([
                'order_ids' => [$order->id],
                'method' => 'cash',
            ])->assertCreated();

            $this->assertSame('pending', $response->json('status'));
            $this->assertSame($user->id, $response->json('requested_by'));

            $this->assertDatabaseHas('collection_requests', [
                'id' => $response->json('id'),
                'status' => 'pending',
                'requested_by' => $user->id,
                'restaurant_id' => $this->restaurant->id,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 2. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_a_waiter_cannot_create_a_request_for_another_restaurants_order(): void
    {
        $this->actingAsRole('waiter');

        $otherRestaurant = Restaurant::factory()->create();
        $otherTable = Table::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'status' => 'occupied',
            'number' => '9',
        ]);
        $foreignOrder = $this->servedOrder($otherRestaurant, $otherTable);

        $this->createRequest([
            'order_ids' => [$foreignOrder->id],
            'method' => 'cash',
        ])->assertNotFound();

        $this->assertDatabaseCount('collection_requests', 0);
    }

    // ------------------------------------------------------------------
    // 3. Contenu : au moins une commande servie, même table
    // ------------------------------------------------------------------

    public function test_a_request_needs_at_least_one_served_order_of_the_same_table(): void
    {
        $this->actingAsRole('waiter');

        // 3a. sans order_ids
        $this->createRequest(['method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_ids');

        // 3b. commande non servie
        $openOrder = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'ready',
        ]);
        $this->createRequest(['order_ids' => [$openOrder->id], 'method' => 'cash'])
            ->assertStatus(422);

        // 3c. deux commandes de tables différentes
        $otherTable = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '2',
        ]);
        $orderA = $this->servedOrder(null, $this->table);
        $orderB = $this->servedOrder(null, $otherTable);
        $this->createRequest(['order_ids' => [$orderA->id, $orderB->id], 'method' => 'cash'])
            ->assertStatus(422);

        // 3d. commande servie déjà payée : due_amount = 0
        $paidOrder = $this->servedOrder();
        Payment::create([
            'id' => (string) Str::uuid(),
            'order_id' => $paidOrder->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'amount' => $paidOrder->due_amount,
            'method' => 'cash',
            'operator' => null,
            'reference' => null,
            'transaction_data' => ['receipt_confirmed_at' => now()->toIso8601String()],
            'status' => 'completed',
        ]);
        $paidOrder->recalculate();

        $this->assertSame(0, $paidOrder->fresh()->due_amount);

        $this->createRequest(['order_ids' => [$paidOrder->id], 'method' => 'cash'])
            ->assertStatus(422);

        $this->assertDatabaseCount('collection_requests', 0);
    }

    // ------------------------------------------------------------------
    // 4. Plusieurs commandes d'une même table sont regroupées
    // ------------------------------------------------------------------

    public function test_all_orders_of_a_request_belong_to_the_same_table_and_restaurant(): void
    {
        $this->actingAsRole('waiter');
        $orderA = $this->servedOrder();
        $orderB = $this->servedOrder();

        $response = $this->createRequest([
            'order_ids' => [$orderA->id, $orderB->id],
            'method' => 'cash',
        ])->assertCreated();

        $id = $response->json('id');

        // deux lignes dans le pivot
        $this->assertDatabaseHas('collection_request_order', ['collection_request_id' => $id, 'order_id' => $orderA->id]);
        $this->assertDatabaseHas('collection_request_order', ['collection_request_id' => $id, 'order_id' => $orderB->id]);

        // total_amount = somme des due_amount
        $expected = $orderA->fresh()->due_amount + $orderB->fresh()->due_amount;
        $this->assertSame($expected, $response->json('total_amount'));

        // aucune ligne Payment créée à la demande
        $this->assertDatabaseCount('payments', 0);
    }

    // ------------------------------------------------------------------
    // 5. Mobile Money : opérateur + numéro actif
    // ------------------------------------------------------------------

    public function test_a_mobile_money_request_needs_an_operator_and_an_active_payment_number(): void
    {
        $this->actingAsRole('waiter');

        // cash sans opérateur : ok
        $order = $this->servedOrder();
        $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])->assertCreated();

        // mobile_money sans opérateur : 422
        $order = $this->servedOrder();
        $this->createRequest(['order_ids' => [$order->id], 'method' => 'mobile_money'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operator');

        // opérateur inconnu : 422
        $order = $this->servedOrder();
        $this->createRequest([
            'order_ids' => [$order->id],
            'method' => 'mobile_money',
            'operator' => 'orange_money',
        ])->assertStatus(422);

        // opérateur valide mais aucun numéro actif : 422
        $order = $this->servedOrder();
        $this->createRequest([
            'order_ids' => [$order->id],
            'method' => 'mobile_money',
            'operator' => 'mtn_momo',
        ])->assertStatus(422);

        // opérateur valide + numéro actif : 201
        PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'mtn_momo',
            'phone_number' => '+22997000001',
            'beneficiary_name' => 'Caisse A',
            'is_active' => true,
        ]);

        $order = $this->servedOrder();
        $response = $this->createRequest([
            'order_ids' => [$order->id],
            'method' => 'mobile_money',
            'operator' => 'mtn_momo',
        ])->assertCreated();

        $this->assertSame('mtn_momo', $response->json('operator'));
        $this->assertSame('+22997000001', $response->json('phone_number'));
        $this->assertSame('Caisse A', $response->json('beneficiary_name'));
    }

    // ------------------------------------------------------------------
    // 6. Le numéro est figé à la création
    // ------------------------------------------------------------------

    public function test_the_payment_number_is_frozen_at_creation(): void
    {
        $this->actingAsRole('waiter');

        $number = PaymentNumber::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'operator' => 'moov_money',
            'phone_number' => '+22996000002',
            'beneficiary_name' => 'Caisse B',
            'is_active' => true,
        ]);

        $order = $this->servedOrder();
        $id = $this->createRequest([
            'order_ids' => [$order->id],
            'method' => 'mobile_money',
            'operator' => 'moov_money',
        ])->assertCreated()->json('id');

        // On désactive (et on modifie) le numéro
        $number->update(['is_active' => false, 'phone_number' => '+22999999999', 'beneficiary_name' => 'Autre']);

        // La demande pending conserve les valeurs d'origine
        $fresh = $this->getJson("/api/collection-requests/{$id}")->assertOk();

        $this->assertSame('+22996000002', $fresh->json('phone_number'));
        $this->assertSame('Caisse B', $fresh->json('beneficiary_name'));
    }

    // ------------------------------------------------------------------
    // 7. Une commande = une seule demande pending
    // ------------------------------------------------------------------

    public function test_an_order_cannot_be_in_two_pending_requests(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $first = $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertCreated()->json('id');

        // 2e demande avec la même commande : 422
        $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertStatus(422);

        // On annule la 1re, la 2e redevient possible
        $this->deleteJson("/api/collection-requests/{$first}")->assertOk();

        $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertCreated();
    }

    // ------------------------------------------------------------------
    // 8. Demande pending figée : pas d'édition
    // ------------------------------------------------------------------

    public function test_a_pending_request_is_frozen_and_cannot_be_edited(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $id = $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertCreated()->json('id');

        $this->patchJson("/api/collection-requests/{$id}", ['method' => 'mobile_money'])
            ->assertStatus(405);
    }

    // ------------------------------------------------------------------
    // 9. Annulation : règles d'équipe
    // ------------------------------------------------------------------

    public function test_any_waiter_of_the_restaurant_can_cancel_a_pending_request(): void
    {
        $waiterA = $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $id = $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertCreated()->json('id');

        // serveur B (même restaurant) annule
        $waiterB = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);
        Sanctum::actingAs($waiterB);

        $this->deleteJson("/api/collection-requests/{$id}")->assertOk();

        $this->assertDatabaseHas('collection_requests', [
            'id' => $id,
            'status' => 'cancelled',
        ]);
        $this->assertNotNull(
            \App\Models\CollectionRequest::find($id)->cancelled_at
        );

        // ---- cashier refusé ----
        $waiterC = $this->actingAsRole('waiter');
        $order2 = $this->servedOrder();
        $id2 = $this->createRequest(['order_ids' => [$order2->id], 'method' => 'cash'])
            ->assertCreated()->json('id');

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'cashier',
        ]));
        $this->deleteJson("/api/collection-requests/{$id2}")->assertForbidden();

        // ---- autre restaurant → 404 ----
        Sanctum::actingAs($waiterC);
        $otherRestaurant = Restaurant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'role' => 'admin',
        ]));
        $this->deleteJson("/api/collection-requests/{$id2}")->assertNotFound();

        // ---- manager du même restaurant : ok ----
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'manager',
        ]));
        $this->deleteJson("/api/collection-requests/{$id2}")->assertOk();

        // ---- demande déjà validée : 422 (on force le statut en base) ----
        $order3 = $this->servedOrder();
        Sanctum::actingAs($waiterC);
        $id3 = $this->createRequest(['order_ids' => [$order3->id], 'method' => 'cash'])
            ->assertCreated()->json('id');
        \App\Models\CollectionRequest::whereKey($id3)->update(['status' => 'validated', 'validated_at' => now()]);
        $this->deleteJson("/api/collection-requests/{$id3}")->assertStatus(422);

        // ---- déjà annulée : 422 ----
        $this->deleteJson("/api/collection-requests/{$id}")->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 10. Liste filtrée + isolation
    // ------------------------------------------------------------------

    public function test_the_cashier_sees_pending_requests_and_can_filter_by_status(): void
    {
        // 3 demandes : 2 pending, 1 cancelled
        $this->actingAsRole('waiter');

        $orderA = $this->servedOrder();
        $idA = $this->createRequest(['order_ids' => [$orderA->id], 'method' => 'cash'])->json('id');
        $orderB = $this->servedOrder();
        $idB = $this->createRequest(['order_ids' => [$orderB->id], 'method' => 'cash'])->json('id');
        $orderC = $this->servedOrder();
        $idC = $this->createRequest(['order_ids' => [$orderC->id], 'method' => 'cash'])->json('id');
        $this->deleteJson("/api/collection-requests/{$idC}")->assertOk();

        // une demande d'un autre restaurant ne doit jamais apparaître
        $otherRestaurant = Restaurant::factory()->create();
        $otherTable = Table::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'status' => 'occupied',
            'number' => '7',
        ]);
        $otherWaiter = User::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'role' => 'waiter',
        ]);
        Sanctum::actingAs($otherWaiter);
        $foreignOrder = $this->servedOrder($otherRestaurant, $otherTable);
        $foreignId = $this->createRequest(['order_ids' => [$foreignOrder->id], 'method' => 'cash'])->json('id');

        // Le cashier du restaurant A ne voit que A
        $this->actingAsRole('cashier');

        $pending = $this->getJson('/api/collection-requests?status=pending')->assertOk()->json('data');
        $this->assertCount(2, $pending);
        $pendingIds = array_column($pending, 'id');
        $this->assertContains($idA, $pendingIds);
        $this->assertContains($idB, $pendingIds);
        $this->assertNotContains($idC, $pendingIds);
        $this->assertNotContains($foreignId, $pendingIds);

        $all = $this->getJson('/api/collection-requests')->assertOk()->json('data');
        $allIds = array_column($all, 'id');
        $this->assertContains($idA, $allIds);
        $this->assertContains($idB, $allIds);
        $this->assertContains($idC, $allIds);
        $this->assertNotContains($foreignId, $allIds);
    }

    // ------------------------------------------------------------------
    // 11. Détail + isolation
    // ------------------------------------------------------------------

    public function test_the_cashier_can_view_the_detail_of_a_pending_request(): void
    {
        $waiter = $this->actingAsRole('waiter');
        $order = $this->servedOrder();

        $id = $this->createRequest(['order_ids' => [$order->id], 'method' => 'cash'])
            ->assertCreated()->json('id');

        $this->actingAsRole('cashier');

        $detail = $this->getJson("/api/collection-requests/{$id}")->assertOk();

        $this->assertSame('pending', $detail->json('status'));
        $this->assertSame('cash', $detail->json('method'));
        $this->assertSame($waiter->id, $detail->json('requested_by'));
        $this->assertSame(1230, $detail->json('total_amount'));

        // autre restaurant → 404
        $otherRestaurant = Restaurant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'role' => 'cashier',
        ]));
        $this->getJson("/api/collection-requests/{$id}")->assertNotFound();
    }
}
