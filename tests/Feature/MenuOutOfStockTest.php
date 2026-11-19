<?php

namespace Tests\Feature;

use App\Events\MenuItemOutOfStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuOutOfStockTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    private User $admin;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->admin = User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => 'admin']);
        $this->waiter = User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => 'waiter']);
        $this->table = Table::factory()->create(['restaurant_id' => $this->restaurant->id, 'status' => 'occupied']);
        $this->dish = MenuItem::factory()->create(['restaurant_id' => $this->restaurant->id, 'price' => 1000]);
    }

    private function sentOrder(): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->waiter->id,
            'status' => 'open',
            'confirmed_at' => now(),
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
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

    // ------------------------------------------------------------------
    // 1. Marquer un plat en rupture
    // ------------------------------------------------------------------

    public function test_admin_can_mark_item_out_of_stock(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", [
            'reason' => 'Plus d\'ingrédients',
        ])->assertOk();

        $fresh = $this->dish->fresh();
        $this->assertFalse($fresh->is_available);
        $this->assertNotNull($fresh->out_of_stock_at);
        $this->assertSame('Plus d\'ingrédients', $fresh->out_of_stock_reason);
        $this->assertSame($this->admin->id, $fresh->out_of_stock_by);
    }

    // ------------------------------------------------------------------
    // 2. Notification aux commandes envoyées
    // ------------------------------------------------------------------

    public function test_marking_out_of_stock_notifies_sent_orders(): void
    {
        Event::fake([MenuItemOutOfStock::class]);

        $order = $this->sentOrder();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", [
            'reason' => 'Plus d\'ingrédients',
        ])->assertOk();

        Event::assertDispatched(MenuItemOutOfStock::class, fn ($event) => $event->order->id === $order->id
        );
    }

    // ------------------------------------------------------------------
    // 3. Pas de notification aux brouillons
    // ------------------------------------------------------------------

    public function test_marking_out_of_stock_does_not_notify_drafts(): void
    {
        Event::fake([MenuItemOutOfStock::class]);

        // Commande brouillon (pas envoyée)
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->waiter->id,
            'status' => 'open',
            'confirmed_at' => null,
        ]);
        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", [
            'reason' => 'Plus d\'ingrédients',
        ])->assertOk();

        Event::assertNotDispatched(MenuItemOutOfStock::class);
    }

    // ------------------------------------------------------------------
    // 4. Commande en cours : le plat est barré
    // ------------------------------------------------------------------

    public function test_kitchen_marks_item_out_of_stock_on_order_and_total_drops(): void
    {
        // 1. Créer une commande in_progress avec 1 plat pending
        $order = $this->sentOrder();
        $order->update(['status' => 'in_progress']);
        $order->items()->update(['kitchen_status' => 'pending']);

        $totalBefore = $order->fresh()->total;
        $this->assertGreaterThan(0, $totalBefore);

        // 2. Le cuisinier clique sur 🚫 depuis la carte cuisine
        $kitchen = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);
        Sanctum::actingAs($kitchen);

        $item = $order->items()->first();
        $this->patchJson("/api/kitchen/items/{$item->id}/rupture")->assertOk();

        // 3. Le plat est barré, retiré du total
        $fresh = $order->fresh();
        $this->assertLessThan($totalBefore, $fresh->total);
        $this->assertNotNull($item->fresh()->out_of_stock_at);
    }

    // ------------------------------------------------------------------
    // 5. Réactivation
    // ------------------------------------------------------------------

    public function test_admin_can_reactivate_item(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test']);

        $this->patchJson("/api/menu/{$this->dish->id}/in-stock")->assertOk();

        $fresh = $this->dish->fresh();
        $this->assertTrue($fresh->is_available);
        $this->assertNull($fresh->out_of_stock_at);
    }

    // ------------------------------------------------------------------
    // 6. Isolation
    // ------------------------------------------------------------------

    public function test_out_of_stock_is_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherDish = MenuItem::factory()->create(['restaurant_id' => $other->id]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$otherDish->id}/out-of-stock", ['reason' => 'Test'])
            ->assertNotFound();
    }

    public function test_cooking_item_can_be_marked_out_of_stock_by_kitchen(): void
    {
        $order = $this->sentOrder();
        $order->update(['status' => 'in_progress']);
        $order->items()->update(['kitchen_status' => 'cooking']);

        $totalBefore = $order->fresh()->total;

        $kitchen = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);
        Sanctum::actingAs($kitchen);

        $item = $order->items()->first();
        $this->patchJson("/api/kitchen/items/{$item->id}/rupture")->assertOk();

        $fresh = $order->fresh();
        $this->assertLessThan($totalBefore, $fresh->total);
        $this->assertNotNull($item->fresh()->out_of_stock_at);
    }
}
