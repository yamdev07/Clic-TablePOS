<?php

namespace Tests\Feature;

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

class KitchenTest extends TestCase
{
    use RefreshDatabase;

    private User      $kitchenUser;
    private Order     $order;
    private OrderItem $item;
    private MenuItem  $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();

        $this->kitchenUser = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role'          => 'kitchen',
        ]);

        $table = Table::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status'        => 'occupied',
        ]);

        $this->menuItem = MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Poulet braisé',
            'price'         => 3500,
            'is_available'  => true,
        ]);

        $this->order = Order::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id'      => $table->id,
            'user_id'       => $this->kitchenUser->id,
            'status'        => 'in_progress',
        ]);

        $this->item = OrderItem::create([
            'id'             => (string) Str::uuid(),
            'order_id'       => $this->order->id,
            'menu_item_id'   => $this->menuItem->id,
            'item_name'      => 'Poulet braisé',
            'quantity'       => 2,
            'unit_price'     => 3500,
            'total_price'    => 7000,
            'kitchen_status' => 'pending',
        ]);

        Sanctum::actingAs($this->kitchenUser);
    }

    /** @test */
    public function kitchen_can_start_cooking_an_item(): void
    {
        $response = $this->patchJson("/api/kitchen/items/{$this->item->id}/cooking");

        $response->assertStatus(200);
        $this->assertDatabaseHas('order_items', [
            'id'             => $this->item->id,
            'kitchen_status' => 'cooking',
        ]);
    }

    /** @test */
    public function kitchen_can_mark_item_ready(): void
    {
        $this->item->update(['kitchen_status' => 'cooking']);

        $response = $this->patchJson("/api/kitchen/items/{$this->item->id}/ready");

        $response->assertStatus(200);
        $this->assertDatabaseHas('order_items', [
            'id'             => $this->item->id,
            'kitchen_status' => 'ready',
        ]);
    }

    /** @test */
    public function kitchen_can_mark_item_served(): void
    {
        $this->item->update(['kitchen_status' => 'ready']);

        $response = $this->patchJson("/api/kitchen/items/{$this->item->id}/serve");

        $response->assertStatus(200);
        $this->assertDatabaseHas('order_items', [
            'id'             => $this->item->id,
            'kitchen_status' => 'served',
        ]);
    }

    /** @test */
    public function kitchen_can_signal_out_of_stock(): void
    {
        $response = $this->patchJson("/api/kitchen/items/{$this->item->id}/rupture");

        $response->assertStatus(200);

        // Le menu item doit être marqué indisponible
        $this->assertDatabaseHas('menu_items', [
            'id'           => $this->menuItem->id,
            'is_available' => false,
        ]);

        // L'item de commande doit être retiré de la queue
        $this->assertDatabaseHas('order_items', [
            'id'             => $this->item->id,
            'kitchen_status' => 'served',
        ]);
    }

    /** @test */
    public function pending_orders_endpoint_returns_correct_statuses(): void
    {
        // Créer un item en cuisson et un item prêt
        $cookingItem = OrderItem::create([
            'id'             => (string) Str::uuid(),
            'order_id'       => $this->order->id,
            'menu_item_id'   => $this->menuItem->id,
            'item_name'      => 'Riz sauce',
            'quantity'       => 1,
            'unit_price'     => 2000,
            'total_price'    => 2000,
            'kitchen_status' => 'cooking',
        ]);

        $readyItem = OrderItem::create([
            'id'             => (string) Str::uuid(),
            'order_id'       => $this->order->id,
            'menu_item_id'   => $this->menuItem->id,
            'item_name'      => 'Alloco',
            'quantity'       => 1,
            'unit_price'     => 1500,
            'total_price'    => 1500,
            'kitchen_status' => 'ready',
        ]);

        $response = $this->getJson('/api/kitchen/pending');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id');

        // Les trois statuts (pending, cooking, ready) doivent être présents
        $this->assertTrue($ids->contains($this->item->id));
        $this->assertTrue($ids->contains($cookingItem->id));
        $this->assertTrue($ids->contains($readyItem->id));
    }

    /** @test */
    public function special_instructions_are_visible_in_kitchen(): void
    {
        $this->item->update(['special_instructions' => 'sans piment, bien cuit']);

        $response = $this->getJson('/api/kitchen/pending');

        $response->assertStatus(200);
        $found = collect($response->json())->firstWhere('id', $this->item->id);
        $this->assertEquals('sans piment, bien cuit', $found['special_instructions']);
    }
}
