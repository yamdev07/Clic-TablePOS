<?php

// tests/Unit/OrderTest.php

namespace Tests\Unit;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Table $table;

    private MenuItem $menuItem;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un restaurant
        $this->restaurant = Restaurant::factory()->create();

        // Créer un utilisateur
        $this->user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        // Créer une table
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
        ]);

        // Créer un menu item
        $this->menuItem = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Burger',
            'price' => 5000,
        ]);
    }

    /** @test */
    public function it_can_create_an_order()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->user->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'open',
        ]);
    }

    /** @test */
    public function it_calculates_total_correctly()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->user->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);

        // Premier item avec le menu item existant
        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'quantity' => 2,
            'unit_price' => $this->menuItem->price,
            'total_price' => $this->menuItem->price * 2,
        ]);

        // Créer un deuxième menu item pour le test
        $menuItem2 = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Coca Cola',
            'price' => 1000,
        ]);

        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $menuItem2->id,
            'item_name' => $menuItem2->name,
            'quantity' => 3,
            'unit_price' => $menuItem2->price,
            'total_price' => $menuItem2->price * 3,
        ]);

        $order->recalculate();

        $this->assertEquals(13000, $order->subtotal);
        $this->assertEquals(2340, $order->tax);
        $this->assertEquals(650, $order->service_charge);
        $this->assertEquals(15990, $order->total);
    }

    /** @test */
    public function it_updates_paid_amount_correctly()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->user->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);

        // Ajouter un item avec le menu item existant
        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'quantity' => 1,
            'unit_price' => $this->menuItem->price,
            'total_price' => $this->menuItem->price,
        ]);

        $order->recalculate();

        // Ajouter un paiement
        $order->payments()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'amount' => 3000,
            'method' => 'cash',
            'status' => 'completed',
        ]);

        // Recalculer après paiement
        $order->recalculate();

        // Rafraîchir l'ordre depuis la base
        $order = $order->fresh();

        // Vérifications
        $this->assertEquals(3000, $order->paid_amount);

        $expectedTotal = $this->menuItem->price; // 5000
        $expectedTax = (int) ($expectedTotal * 0.18); // 900
        $expectedService = (int) ($expectedTotal * 0.05); // 250
        $expectedGrandTotal = $expectedTotal + $expectedTax + $expectedService; // 6150
        $expectedDue = $expectedGrandTotal - 3000; // 3150

        $this->assertEquals($expectedDue, $order->due_amount);
    }
}
