<?php

namespace Tests\Feature;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Events\KitchenOrderUpdated;
use App\Events\MenuItemOutOfStock;
use App\Events\OutOfStockReported;
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
        /** Commande avec un plat du menu pour chaque statut de cuisine donné. */
    private function orderWith(string $status, array $itemStatuses, ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;
        $table = $restaurant->is($this->restaurant)
            ? $this->table
            : Table::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'occupied']);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'status' => $status,
            'confirmed_at' => now(),
        ]);

        foreach ($itemStatuses as $kitchenStatus) {
            OrderItem::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => $kitchenStatus,
            ]);
        }

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
     public function test_only_kitchen_manager_and_admin_can_declare_a_rupture(): void
    {
        foreach (['waiter', 'cashier'] as $role) {
            Sanctum::actingAs(User::factory()->create([
                'restaurant_id' => $this->restaurant->id,
                'role' => $role,
            ]));

            $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])
                ->assertForbidden();

            $this->assertNull($this->dish->fresh()->out_of_stock_at, "rôle {$role}");
            $this->assertTrue($this->dish->fresh()->is_available, "rôle {$role}");
        }

        foreach (['kitchen', 'manager', 'admin'] as $role) {
            $dish = MenuItem::factory()->create(['restaurant_id' => $this->restaurant->id]);
            Sanctum::actingAs(User::factory()->create([
                'restaurant_id' => $this->restaurant->id,
                'role' => $role,
            ]));

            $this->patchJson("/api/menu/{$dish->id}/out-of-stock", ['reason' => 'Test'])
                ->assertOk();

            $this->assertNotNull($dish->fresh()->out_of_stock_at, "rôle {$role}");
        }
    }

    public function test_only_manager_and_admin_can_put_a_dish_back_in_stock(): void
{
    foreach (['waiter', 'cashier', 'kitchen'] as $role) {
        $this->dish->refresh()->update([
            'is_available' => false,
            'out_of_stock_at' => now(),
            'out_of_stock_reason' => 'Test',
        ]);
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]));

        $this->patchJson("/api/menu/{$this->dish->id}/in-stock")->assertForbidden();
        $this->assertNotNull($this->dish->fresh()->out_of_stock_at, "rôle {$role}");
    }

    foreach (['manager', 'admin'] as $role) {
        // IMPORTANT : récupérer une instance fraîche pour chaque rôle
        $dish = MenuItem::find($this->dish->id);
        $dish->update([
            'is_available' => false,
            'out_of_stock_at' => now(),
            'out_of_stock_reason' => 'Test',
        ]);

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]));

        $this->patchJson("/api/menu/{$dish->id}/in-stock")->assertOk();
        $this->assertNull($dish->fresh()->out_of_stock_at, "rôle {$role}");
        $this->assertTrue($dish->fresh()->is_available, "rôle {$role}");
    }
}
    public function test_a_menu_rupture_strikes_only_pending_items_of_launched_orders(): void
    {
        $order = $this->orderWith('in_progress', ['pending', 'cooking']);
        $totalBefore = (int) $order->total;

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        $this->assertSame(1, $order->items()->whereNotNull('out_of_stock_at')->count());
        $this->assertSame(1, $order->items()->where('kitchen_status', 'cooking')->whereNull('out_of_stock_at')->count());
        $this->assertLessThan($totalBefore, (int) $order->fresh()->total);
    }

    public function test_ready_and_served_orders_are_left_untouched_by_a_menu_rupture(): void
    {
        Event::fake([KitchenOrderUpdated::class, OutOfStockReported::class]);
        $ready = $this->orderWith('ready', ['ready']);
        $served = $this->orderWith('served', ['served']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        foreach ([$ready, $served] as $order) {
            $this->assertNull($order->items()->first()->out_of_stock_at);
            $this->assertEquals($order->total, $order->fresh()->total);
        }
        Event::assertNotDispatched(KitchenOrderUpdated::class);
        Event::assertNotDispatched(OutOfStockReported::class);
    }

    public function test_the_kitchen_screen_is_updated_for_each_order_struck_by_a_menu_rupture(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $launched = $this->orderWith('in_progress', ['pending']);
        $this->orderWith('in_progress', ['cooking']); // rien à barrer : pas d'événement

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, function ($event) use ($launched) {
            $items = $event->broadcastWith()['items'];

            return $event->order->id === $launched->id
                && count($items) === 1
                && $items[0]['out_of_stock'] === true;
        });
    }

    public function test_the_waiter_is_told_about_each_item_struck_in_a_launched_order(): void
    {
        Event::fake([OutOfStockReported::class, MenuItemOutOfStock::class]);
        $launched = $this->orderWith('in_progress', ['pending']);
        $sent = $this->orderWith('open', ['pending']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        Event::assertDispatchedTimes(OutOfStockReported::class, 1);
        Event::assertDispatched(OutOfStockReported::class, fn ($event) => $event->order->id === $launched->id
            && $event->item->out_of_stock_at !== null);

        // La commande seulement envoyée reçoit le message « à modifier ou annuler »
        Event::assertDispatchedTimes(MenuItemOutOfStock::class, 1);
        Event::assertDispatched(MenuItemOutOfStock::class, fn ($event) => $event->order->id === $sent->id);
    }

    public function test_orders_of_another_restaurant_are_not_touched_by_a_menu_rupture(): void
    {
        Event::fake([MenuItemOutOfStock::class, KitchenOrderUpdated::class, OutOfStockReported::class]);
        $other = Restaurant::factory()->create();
        $this->orderWith('open', ['pending'], $other);
        $foreignLaunched = $this->orderWith('in_progress', ['pending'], $other);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        $this->assertNull($foreignLaunched->items()->first()->out_of_stock_at);
        Event::assertNotDispatched(MenuItemOutOfStock::class);
        Event::assertNotDispatched(KitchenOrderUpdated::class);
        Event::assertNotDispatched(OutOfStockReported::class);
    }

    public function test_the_rupture_response_does_not_expose_the_author_contact_details(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])
            ->assertOk()
            ->assertJsonPath('out_of_stock_by.name', $this->admin->name)
            ->assertJsonMissingPath('out_of_stock_by.email')
            ->assertJsonMissingPath('out_of_stock_by.phone');
    }

    public function test_a_rupture_needs_a_reason_and_cannot_be_declared_twice(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
        $this->assertNull($this->dish->fresh()->out_of_stock_at);

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Autre'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce plat est déjà en rupture.');
        $this->assertSame('Test', $this->dish->fresh()->out_of_stock_reason);
    }
        public function test_toggling_availability_back_on_clears_the_rupture(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        $this->patchJson("/api/menu/{$this->dish->id}/availability")->assertOk();

        $fresh = $this->dish->fresh();
        $this->assertTrue($fresh->is_available);
        $this->assertNull($fresh->out_of_stock_at);
        $this->assertNull($fresh->out_of_stock_reason);
        $this->assertNull($fresh->out_of_stock_by);
    }
        public function test_the_menu_rupture_event_targets_the_restaurant_channel_without_amounts(): void
    {
        $order = $this->sentOrder();
        $event = new MenuItemOutOfStock($this->dish, $order);

        $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();
        $this->assertSame(['private-restaurant.'.$this->restaurant->id], $channels);
        $this->assertSame('menu.item.out_of_stock', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($this->dish->id, $payload['menu_item_id']);
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame($order->order_number, $payload['order_number']);
        $this->assertSame($this->table->number, $payload['table_number']);
        $this->assertStringContainsString($this->dish->name, $payload['message']);

        foreach (['total', 'subtotal', 'due_amount', 'paid_amount', 'price'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }
        public function test_the_kitchen_screen_flags_the_dish_on_sent_orders_when_a_menu_rupture_is_declared(): void
    {
        Event::fake([KitchenOrderUpdated::class, MenuItemOutOfStock::class]);
        $sent = $this->orderWith('open', ['pending']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/menu/{$this->dish->id}/out-of-stock", ['reason' => 'Test'])->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, function ($event) use ($sent) {
            $payload = $event->broadcastWith();

            return $event->order->id === $sent->id
                && $payload['kitchen_stage'] === 'pending'
                && count($payload['items']) === 1
                && $payload['items'][0]['menu_out_of_stock'] === true;
        });
    }

    public function test_putting_a_dish_back_in_stock_refreshes_the_kitchen_screen(): void
    {
        $sent = $this->orderWith('open', ['pending']);
        $this->dish->update([
            'is_available' => false,
            'out_of_stock_at' => now(),
            'out_of_stock_reason' => 'Test',
        ]);
        Event::fake([KitchenOrderUpdated::class]);

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'manager',
        ]));
        $this->patchJson("/api/menu/{$this->dish->id}/in-stock")->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, function ($event) use ($sent) {
            $items = $event->broadcastWith()['items'];

            return $event->order->id === $sent->id && $items[0]['menu_out_of_stock'] === false;
        });
    }
}
