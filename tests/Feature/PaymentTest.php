<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User       $user;
    private Restaurant $restaurant;
    private Order      $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role'          => 'admin',
        ]);

        $table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status'        => 'occupied',
        ]);

        $menuItem = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price'         => 5000,
        ]);

        // Commande vide, sera recalculée après ajout des items
        $this->order = Order::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $table->id,
            'user_id'       => $this->user->id,
            'status'        => 'in_progress',
        ]);

        // 2 items à 5000 → subtotal=10000, tax=1800, service=500, total=12300
        OrderItem::create([
            'id'           => (string) Str::uuid(),
            'order_id'     => $this->order->id,
            'menu_item_id' => $menuItem->id,
            'item_name'    => $menuItem->name,
            'quantity'     => 2,
            'unit_price'   => 5000,
            'total_price'  => 10000,
        ]);

        $this->order->recalculate();
        $this->order->refresh();

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function cashier_can_process_exact_payment(): void
    {
        $due = $this->order->due_amount; // 12300

        $response = $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $due,
            'method' => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'amount'   => $due,
            'method'   => 'cash',
            'status'   => 'completed',
        ]);
    }

    /** @test */
    public function cash_overpayment_is_capped_at_due_amount(): void
    {
        $due  = $this->order->due_amount; // 12300
        $given = $due + 5000;             // 17300

        $response = $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount'     => $given,
            'method'     => 'cash',
            'cash_given' => $given,
        ]);

        $response->assertStatus(201);

        // Le montant enregistré doit être plafonné à due_amount
        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'amount'   => $due,
        ]);
    }

    /** @test */
    public function non_cash_overpayment_is_rejected(): void
    {
        $response = $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $this->order->due_amount + 5000,
            'method' => 'wave',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function order_marked_paid_when_fully_settled(): void
    {
        $due = $this->order->due_amount;

        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $due,
            'method' => 'cash',
        ]);

        $this->assertDatabaseHas('orders', [
            'id'          => $this->order->id,
            'paid_amount' => $due,
            'due_amount'  => 0,
        ]);
    }

    /** @test */
    public function partial_payment_reduces_due_amount(): void
    {
        $partial = 4000;
        $remaining = $this->order->due_amount - $partial;

        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $partial,
            'method' => 'cash',
        ]);

        $this->assertDatabaseHas('orders', [
            'id'          => $this->order->id,
            'paid_amount' => $partial,
            'due_amount'  => $remaining,
        ]);
    }

    /** @test */
    public function cannot_pay_already_settled_order(): void
    {
        // Premier paiement — solde tout
        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $this->order->due_amount,
            'method' => 'cash',
        ]);

        // Deuxième tentative sur commande déjà soldée
        $response = $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ]);

        $response->assertStatus(422)
                 ->assertJsonFragment(['message' => 'Cette commande est déjà entièrement payée']);
    }

    /** @test */
    public function payment_reference_is_saved(): void
    {
        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount'    => 10000,
            'method'    => 'wave',
            'reference' => 'WAVE-20240413-001',
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id'  => $this->order->id,
            'reference' => 'WAVE-20240413-001',
        ]);
    }

    /** @test */
    public function payment_requires_valid_method(): void
    {
        $response = $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => 5000,
            'method' => 'bitcoin',
        ]);

        $response->assertStatus(422);
    }
}
