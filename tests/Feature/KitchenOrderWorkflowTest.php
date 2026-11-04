<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KitchenOrderWorkflowTest extends TestCase
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
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
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

    /** Crée une commande avec un plat pour chaque statut de cuisine donné. */
    private function order(string $status, array $itemStatuses, ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;
        $table = $restaurant->is($this->restaurant)
            ? $this->table
            : Table::factory()->create(['restaurant_id' => $restaurant->id]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'status' => $status,
        ]);

        foreach ($itemStatuses as $kitchenStatus) {
            $order->items()->create([
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => $kitchenStatus,
            ]);
        }

        return $order;
    }

    private function startUrl(Order $order): string
    {
        return "/api/kitchen/orders/{$order->id}/cooking";
    }

    public function test_kitchen_can_start_the_whole_order(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'pending', 'pending']);

        $this->patchJson($this->startUrl($order))->assertOk();

        foreach ($order->items()->get() as $item) {
            $this->assertSame('cooking', $item->kitchen_status);
            $this->assertNotNull($item->started_at);
        }
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_an_existing_started_at_is_not_overwritten(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending']);
        $item = $order->items()->first();
        $earlier = now()->subHour()->startOfSecond();
        $item->forceFill(['started_at' => $earlier])->save();

        $this->patchJson($this->startUrl($order))->assertOk();

        $this->assertTrue($item->fresh()->started_at->equalTo($earlier));
    }

    public function test_only_pending_items_are_started(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'served']);

        $this->patchJson($this->startUrl($order))->assertOk();

        $this->assertSame(1, $order->items()->where('kitchen_status', 'cooking')->count());
        $served = $order->items()->where('kitchen_status', 'served')->first();
        $this->assertNotNull($served);
        $this->assertNull($served->started_at);
    }

    public function test_an_order_that_is_not_in_progress_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        foreach (['open', 'ready', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->order($status, ['pending']);

            $this->patchJson($this->startUrl($order))
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $item = $order->items()->first();
            $this->assertSame('pending', $item->kitchen_status, "commande {$status}");
            $this->assertNull($item->started_at, "commande {$status}");
        }
    }

    public function test_an_order_cannot_be_started_twice(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending']);

        $this->patchJson($this->startUrl($order))->assertOk();
        $first = $order->items()->first()->started_at;

        $this->patchJson($this->startUrl($order))->assertStatus(422);

        $this->assertNotNull($first);
        $this->assertTrue($order->items()->first()->started_at->equalTo($first));
    }

    public function test_an_order_without_pending_items_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        $onlyServed = $this->order('in_progress', ['served']);
        $this->patchJson($this->startUrl($onlyServed))->assertStatus(422);

        $empty = $this->order('in_progress', []);
        $this->patchJson($this->startUrl($empty))->assertStatus(422);
    }

    public function test_only_kitchen_and_admin_can_start_an_order(): void
    {
        $this->actingAsRole('waiter');
        $refused = $this->order('in_progress', ['pending']);
        $this->patchJson($this->startUrl($refused))->assertForbidden();
        $this->assertSame('pending', $refused->items()->first()->kitchen_status);

        foreach (['kitchen', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('in_progress', ['pending']);

            $this->patchJson($this->startUrl($order))->assertOk();
            $this->assertSame('cooking', $order->items()->first()->kitchen_status, "rôle {$role}");
        }
    }

    public function test_an_order_of_another_restaurant_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        // La route doit exister : sans cela, le 404 ci-dessous passerait à tort
        $own = $this->order('in_progress', ['pending']);
        $this->patchJson($this->startUrl($own))->assertOk();

        $foreign = $this->order('in_progress', ['pending'], Restaurant::factory()->create());
        $this->patchJson($this->startUrl($foreign))->assertNotFound();

        $this->assertSame('pending', $foreign->items()->first()->kitchen_status);
    }
}
