<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Commande active = in_progress, ready ou served. Une commande open est un brouillon, inactive.
 * La table pointe vers la plus ancienne commande active ; s'il n'y en a plus, elle est libérée.
 */
class TableCurrentOrderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $admin;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->admin = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);

        Sanctum::actingAs($this->admin);
    }

    /** Crée une commande sur la table, avec un plat, à la date donnée. */
    private function order(string $status, CarbonInterface $createdAt): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => $status,
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();

        $order->items()->create([
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);
        $order->recalculate();

        return $order->fresh();
    }

    private function pointTo(Order $order): void
    {
        $this->table->update(['status' => 'occupied', 'current_order_id' => $order->id]);
    }

    private function pay(Order $order): void
    {
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();
    }

    private function assertTable(string $status, ?string $currentOrderId, string $message = ''): void
    {
        $table = $this->table->fresh();

        $this->assertSame($status, $table->status, $message);
        $this->assertSame($currentOrderId, $table->current_order_id, $message);
    }

    public function test_a_single_paid_order_frees_the_table_and_clears_the_pointer(): void
    {
        $order = $this->order('served', now()->subMinutes(10));
        $this->pointTo($order);

        $this->pay($order);

        $this->assertTable('free', null);
    }

    public function test_pointer_follows_the_oldest_active_order_as_orders_are_paid(): void
    {
        $first = $this->order('served', now()->subMinutes(30));
        $second = $this->order('served', now()->subMinutes(20));
        $third = $this->order('served', now()->subMinutes(10));
        $this->pointTo($first);

        $this->pay($first);
        $this->assertTable('occupied', $second->id, 'après le paiement de la 1re');

        $this->pay($second);
        $this->assertTable('occupied', $third->id, 'après le paiement de la 2e');

        $this->pay($third);
        $this->assertTable('free', null, 'après le paiement de la 3e');
    }

    public function test_pointer_follows_the_oldest_active_order_as_orders_are_cancelled(): void
    {
        $first = $this->order('in_progress', now()->subMinutes(30));
        $second = $this->order('in_progress', now()->subMinutes(20));
        $third = $this->order('in_progress', now()->subMinutes(10));
        $this->pointTo($first);

        $this->postJson("/api/orders/{$first->id}/cancel")->assertOk();
        $this->assertTable('occupied', $second->id, "après l'annulation de la 1re");

        $this->postJson("/api/orders/{$second->id}/cancel")->assertOk();
        $this->assertTable('occupied', $third->id, "après l'annulation de la 2e");

        $this->postJson("/api/orders/{$third->id}/cancel")->assertOk();
        $this->assertTable('free', null, "après l'annulation de la 3e");
    }

    public function test_closing_an_order_that_is_not_the_pointed_one_keeps_the_pointer(): void
    {
        $first = $this->order('served', now()->subMinutes(20));
        $second = $this->order('served', now()->subMinutes(10));
        $this->pointTo($first);

        $this->pay($second);

        $this->assertTable('occupied', $first->id);
    }

    public function test_orders_created_at_the_same_second_are_ordered_by_id(): void
    {
        $oldest = $this->order('served', now()->subMinutes(30));
        $sameMoment = now()->subMinutes(20);
        $a = $this->order('served', $sameMoment);
        $b = $this->order('served', $sameMoment);
        $this->pointTo($oldest);

        $this->pay($oldest);

        $this->assertTable('occupied', collect([$a->id, $b->id])->sort()->first());
    }

    public function test_a_draft_order_does_not_keep_the_table_occupied_until_it_is_sent(): void
    {
        $first = $this->order('served', now()->subMinutes(20));
        $draft = $this->order('open', now()->subMinutes(5));
        $this->pointTo($first);

        $this->pay($first);
        $this->assertTable('free', null, 'un brouillon ne compte pas comme active');

        $this->postJson("/api/orders/{$draft->id}/send-to-kitchen")->assertOk();
        $this->assertTable('occupied', $draft->id, "après l'envoi en cuisine du brouillon");
    }

    public function test_cancelling_a_draft_keeps_the_table_and_pointer_of_the_active_order(): void
    {
        $active = $this->order('in_progress', now()->subMinutes(20));
        $draft = $this->order('open', now()->subMinutes(5));
        $this->pointTo($active);

        $this->postJson("/api/orders/{$draft->id}/cancel")->assertOk();

        $this->assertTable('occupied', $active->id);
    }

    public function test_in_progress_and_ready_orders_count_as_active(): void
    {
        $served = $this->order('served', now()->subMinutes(30));
        $inProgress = $this->order('in_progress', now()->subMinutes(20));
        $ready = $this->order('ready', now()->subMinutes(10));
        $this->pointTo($served);

        $this->pay($served);
        $this->assertTable('occupied', $inProgress->id, 'in_progress est active');

        $this->postJson("/api/orders/{$inProgress->id}/cancel")->assertOk();
        $this->assertTable('occupied', $ready->id, 'ready est active');
    }
}
