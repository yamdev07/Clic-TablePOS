<?php
// tests/Unit/OrderTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Créer un restaurant d'abord
        $restaurant = Restaurant::factory()->create();
        
        $this->user = User::factory()->create([
            'restaurant_id' => $restaurant->id
        ]);
        
        $this->table = Table::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => 'free'
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
            'status' => 'open'
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'open'
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
            'status' => 'open'
        ]);

        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => (string) Str::uuid(),
            'item_name' => 'Burger',
            'quantity' => 2,
            'unit_price' => 5000,
            'total_price' => 10000
        ]);

        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => (string) Str::uuid(),
            'item_name' => 'Coca Cola',
            'quantity' => 3,
            'unit_price' => 1000,
            'total_price' => 3000
        ]);

        $order->recalculate();

        $this->assertEquals(13000, $order->subtotal);
        $this->assertEquals(2340, $order->tax); // 18%
        $this->assertEquals(650, $order->service_charge); // 5%
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
            'status' => 'open'
        ]);

        $order->items()->create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => (string) Str::uuid(),
            'item_name' => 'Burger',
            'quantity' => 1,
            'unit_price' => 5000,
            'total_price' => 5000
        ]);

        $order->recalculate();

        $order->payments()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'amount' => 3000,
            'method' => 'cash',
            'status' => 'completed'
        ]);

        $order->recalculate();

        $this->assertEquals(3000, $order->paid_amount);
        $this->assertEquals(3150, $order->due_amount); // 6150 - 3000
    }
}