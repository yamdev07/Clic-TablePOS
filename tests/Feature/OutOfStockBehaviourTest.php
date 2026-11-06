<?php

namespace Tests\Feature;

use App\Events\OutOfStockReported;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutOfStockBehaviourTest extends TestCase
{
    use RefreshDatabase;

    /** Clé qui indique la rupture dans l'écran de la cuisine : à adapter si la vôtre est différente. */
    private const KITCHEN_FLAG = 'out_of_stock';

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
            'is_available' => true,
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

    /** 'rupture' = plat déjà signalé en rupture (statut served et out_of_stock_at renseigné). */
    private function order(string $status, array $itemStatuses): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => $status,
        ]);

        foreach ($itemStatuses as $kitchenStatus) {
            $item = $order->items()->create([
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => $kitchenStatus === 'rupture' ? 'served' : $kitchenStatus,
            ]);

            if ($kitchenStatus === 'rupture') {
                $item->forceFill(['out_of_stock_at' => now()])->save();
            }
        }

        $order->recalculate();

        return $order->fresh();
    }

    private function report($item)
    {
        return $this->patchJson("/api/kitchen/items/{$item->id}/rupture");
    }

    public function test_the_reported_item_is_removed_from_the_bill(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'pending']);
        $this->assertSame(2460, (int) $order->total);

        $this->report($order->items()->orderBy('id')->first())->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(1000, (int) $fresh->subtotal);
        $this->assertSame(1230, (int) $fresh->total);
        $this->assertSame(1230, (int) $fresh->due_amount);
    }

    public function test_an_order_whose_items_are_all_reported_has_an_empty_bill_and_is_not_paid(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending']);

        $this->report($order->items()->first())->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(0, (int) $fresh->total);
        $this->assertSame('in_progress', $fresh->status);
    }

    public function test_a_reported_item_is_not_counted_in_the_best_sellers(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'pending']);
        $this->report($order->items()->orderBy('id')->first())->assertOk();

        $this->actingAsRole('admin');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('best_sellers.0.quantity', 1)
            ->assertJsonPath('best_sellers.0.revenue', 1000);
    }

    public function test_the_waiter_can_cancel_when_only_reported_items_are_not_pending(): void
    {
        $this->actingAsRole('waiter');

        foreach ([['rupture', 'pending'], ['rupture']] as $itemStatuses) {
            $order = $this->order('in_progress', $itemStatuses);

            $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

            $this->assertSame('cancelled', $order->fresh()->status);
        }
    }

    public function test_the_waiter_cannot_cancel_once_another_item_has_started(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->order('in_progress', ['rupture', 'cooking']);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_the_kitchen_screen_keeps_the_order_visible_with_the_reported_item_flagged(): void
    {
        $this->actingAsRole('kitchen');
        $mixed = $this->order('in_progress', ['rupture', 'pending']);
        $onlyReported = $this->order('in_progress', ['rupture']);

        $orders = collect($this->getJson('/api/kitchen/pending')->assertOk()->json())->keyBy('id');

        $this->assertTrue($orders->has($mixed->id), 'commande mixte absente');
        $this->assertTrue($orders->has($onlyReported->id), 'commande entièrement en rupture absente');

        $flags = collect($orders[$mixed->id]['items'])
            ->map(fn ($item) => (bool) ($item[self::KITCHEN_FLAG] ?? false))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([false, true], $flags);
    }

    public function test_reporting_notifies_the_waiters_once_and_a_refused_report_does_not(): void
    {
        Event::fake([OutOfStockReported::class]);
        $this->actingAsRole('kitchen');

        $eligible = $this->order('in_progress', ['pending']);
        $this->report($eligible->items()->first())->assertOk();
        Event::assertDispatchedTimes(OutOfStockReported::class, 1);

        $refused = $this->order('cancelled', ['pending']);
        $this->report($refused->items()->first())->assertStatus(422);
        Event::assertDispatchedTimes(OutOfStockReported::class, 1);
    }

    public function test_the_waiter_order_list_shows_the_reported_item(): void
    {
        $order = $this->order('in_progress', ['rupture', 'pending']);
        $this->actingAsRole('waiter');

        $listed = collect($this->getJson('/api/orders')->assertOk()->json('data'))->firstWhere('id', $order->id);

        $this->assertNotNull($listed);
        $flagged = collect($listed['items'])->filter(fn ($item) => ! empty($item['out_of_stock_at']));
        $this->assertCount(1, $flagged);
    }

    public function test_the_tables_list_shows_the_reported_item_of_the_current_order(): void
    {
        $order = $this->order('in_progress', ['rupture', 'pending']);
        $this->table->update(['current_order_id' => $order->id]);
        $this->actingAsRole('waiter');

        $table = collect($this->getJson('/api/tables')->assertOk()->json())->firstWhere('id', $this->table->id);

        $flagged = collect($table['current_order']['items'])->filter(fn ($item) => ! empty($item['out_of_stock_at']));
        $this->assertCount(1, $flagged);
    }
}
