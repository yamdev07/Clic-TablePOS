<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chaîne complète d'un service, avec les mêmes appels que le frontend :
 * serveur → commande ouverte → cuisinier lance → prêt → servi → encaissé.
 */
class KitchenWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $waiter;
    private array $kitchen;
    private array $cashier;
    private Table $table;
    private MenuItem $poulet;
    private MenuItem $alloco;

    protected function setUp(): void
    {
        parent::setUp();

        $r = Restaurant::factory()->create();
        $this->waiter  = $this->bearer(User::factory()->create(['restaurant_id' => $r->id, 'role' => 'waiter']));
        $this->kitchen = $this->bearer(User::factory()->create(['restaurant_id' => $r->id, 'role' => 'kitchen']));
        $this->cashier = $this->bearer(User::factory()->create(['restaurant_id' => $r->id, 'role' => 'cashier']));
        $this->table   = Table::factory()->create(['restaurant_id' => $r->id, 'status' => 'free']);
        $this->poulet  = MenuItem::factory()->create(['restaurant_id' => $r->id, 'price' => 3000]);
        $this->alloco  = MenuItem::factory()->create(['restaurant_id' => $r->id, 'price' => 1000]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    /** Requête « en tant que » : oublie l'utilisateur de la requête précédente. */
    private function as(array $headers): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($headers);
    }

    /** Le serveur prend la commande exactement comme NewOrder.vue. */
    private function waiterTakesOrder(): Order
    {
        $id = $this->as($this->waiter)->postJson('/api/orders', ['table_id' => $this->table->id])->json('data.id');
        $this->as($this->waiter)->postJson("/api/orders/{$id}/items", ['menu_item_id' => $this->poulet->id, 'quantity' => 2])->assertCreated();
        $this->as($this->waiter)->postJson("/api/orders/{$id}/items", ['menu_item_id' => $this->alloco->id])->assertCreated();
        $this->as($this->waiter)->postJson("/api/orders/{$id}/send-to-kitchen")->assertOk();

        return Order::findOrFail($id);
    }

    private function kitchenQueue(): \Illuminate\Support\Collection
    {
        return collect($this->as($this->kitchen)->getJson('/api/kitchen/pending')->assertOk()->json());
    }

    /** @test */
    public function full_service_chain_from_waiter_to_cashier(): void
    {
        $order = $this->waiterTakesOrder();

        // 1. La commande arrive « ouverte » et le cuisinier la voit
        $this->assertSame('open', $order->status);
        $queue = $this->kitchenQueue();
        $this->assertCount(2, $queue);
        $this->assertSame(['pending'], $queue->pluck('kitchen_status')->unique()->values()->all());

        // 2. Le cuisinier lance un plat → la commande passe « en préparation »
        $first = $queue->first()['id'];
        $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$first}/cooking")
            ->assertOk()->assertJsonPath('order_status', 'in_progress');

        // 3. Tous les plats prêts → la commande passe « prête » automatiquement
        foreach ($queue as $item) {
            $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$item['id']}/cooking")->assertOk();
            $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$item['id']}/ready")->assertOk();
        }
        $this->assertSame('ready', $order->fresh()->status);

        // 4. Le serveur sert les plats → la commande passe « servie »
        foreach ($queue as $item) {
            $this->as($this->waiter)->patchJson("/api/kitchen/items/{$item['id']}/serve")->assertOk();
        }
        $this->assertSame('served', $order->fresh()->status);
        $this->assertCount(0, $this->kitchenQueue());

        // 5. Le caissier encaisse → commande payée, table libérée
        $due = $order->fresh()->due_amount;
        $this->assertGreaterThan(0, $due);
        $this->as($this->cashier)->postJson("/api/orders/{$order->id}/payments", ['amount' => $due, 'method' => 'cash'])
            ->assertCreated();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('free', $this->table->fresh()->status);
    }

    /** @test */
    public function order_not_yet_sent_is_not_shown_in_kitchen(): void
    {
        $id = $this->as($this->waiter)->postJson('/api/orders', ['table_id' => $this->table->id])->json('data.id');
        $this->as($this->waiter)->postJson("/api/orders/{$id}/items", ['menu_item_id' => $this->poulet->id])->assertCreated();

        $this->assertCount(0, $this->kitchenQueue());
    }

    /** @test */
    public function adding_a_dish_to_a_ready_order_sends_it_back_to_preparation(): void
    {
        $order = $this->waiterTakesOrder();
        foreach ($this->kitchenQueue() as $item) {
            $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$item['id']}/ready")->assertOk();
        }
        $this->assertSame('ready', $order->fresh()->status);

        $this->as($this->waiter)->postJson("/api/orders/{$order->id}/items", ['menu_item_id' => $this->alloco->id])->assertCreated();

        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertCount(3, $this->kitchenQueue());
    }

    /** @test */
    public function out_of_stock_dish_is_removed_from_bill_and_order_can_complete(): void
    {
        $order = $this->waiterTakesOrder();
        $queue = $this->kitchenQueue();
        $pouletItem = $queue->firstWhere('menu_item_id', $this->poulet->id);
        $allocoItem = $queue->firstWhere('menu_item_id', $this->alloco->id);
        $totalBefore = $order->fresh()->total;

        $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$pouletItem['id']}/rupture")->assertOk();
        $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$allocoItem['id']}/ready")->assertOk();

        $order->refresh();
        $this->assertLessThan($totalBefore, $order->total);
        $this->assertSame('ready', $order->status);
        $this->assertFalse($this->poulet->fresh()->is_available);
    }

    /** @test */
    public function out_of_stock_on_every_dish_does_not_mark_order_paid(): void
    {
        $order = $this->waiterTakesOrder();
        foreach ($this->kitchenQueue() as $item) {
            $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$item['id']}/rupture")->assertOk();
        }

        $this->assertNotSame('paid', $order->fresh()->status);
        $this->assertSame('occupied', $this->table->fresh()->status);
    }

    /** @test */
    public function order_cannot_be_sent_to_kitchen_twice(): void
    {
        $order = $this->waiterTakesOrder();
        $first = $this->kitchenQueue()->first()['id'];
        $this->as($this->kitchen)->patchJson("/api/kitchen/items/{$first}/cooking")->assertOk();

        $this->as($this->waiter)->postJson("/api/orders/{$order->id}/send-to-kitchen")->assertUnprocessable();
    }
}
