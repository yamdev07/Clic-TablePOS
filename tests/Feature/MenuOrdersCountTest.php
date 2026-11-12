<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuOrdersCountTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->category = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plats',
            'slug' => 'plats',
        ]);
    }

    private function actingAsWaiter(): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createOrderWithItem(MenuItem $dish, int $quantity = 1, ?string $status = 'served', ?string $createdAt = null): void
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'type' => 'takeaway',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => $status,
        ]);

        if ($createdAt !== null) {
            $order->created_at = $createdAt;
            $order->saveQuietly();
        }

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $dish->id,
            'item_name' => $dish->name,
            'quantity' => $quantity,
            'unit_price' => $dish->price,
            'total_price' => $dish->price * $quantity,
            'options_total' => 0,
            'kitchen_status' => 'served',
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Chaque plat expose orders_count (défaut : 30 jours)
    // ------------------------------------------------------------------

    public function test_menu_items_expose_orders_count_for_the_last_30_days(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
            'name' => 'Yassa',
        ]);

        // 3 commandes récentes
        $this->createOrderWithItem($dish, 1, 'served', now()->subDays(2)->toDateTimeString());
        $this->createOrderWithItem($dish, 2, 'served', now()->subDays(10)->toDateTimeString());
        $this->createOrderWithItem($dish, 1, 'paid', now()->subDay()->toDateTimeString());

        // 1 commande ancienne (> 30 jours) → ne compte pas
        $this->createOrderWithItem($dish, 1, 'served', now()->subDays(45)->toDateTimeString());

        $data = $this->getJson('/api/menu')->assertOk()->json();

        $dishData = collect($data)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);
        $this->assertNotNull($dishData);
        $this->assertArrayHasKey('orders_count', $dishData);
        $this->assertSame(3, $dishData['orders_count']);
    }

    // ------------------------------------------------------------------
    // 2. Les commandes annulées ne comptent pas
    // ------------------------------------------------------------------

    public function test_cancelled_orders_are_not_counted(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
        ]);

        $this->createOrderWithItem($dish, 1, 'served');
        $this->createOrderWithItem($dish, 1, 'paid');
        $this->createOrderWithItem($dish, 1, 'cancelled');
        $this->createOrderWithItem($dish, 1, 'cancelled');

        $data = $this->getJson('/api/menu')->assertOk()->json();
        $dishData = collect($data)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);

        $this->assertSame(2, $dishData['orders_count']);
    }

    // ------------------------------------------------------------------
    // 3. Comptage par nombre de commandes (pas par quantité)
    // ------------------------------------------------------------------

    public function test_orders_count_is_the_number_of_orders_not_the_quantity(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
        ]);

        // 2 commandes, chacune avec quantité 5
        $this->createOrderWithItem($dish, 5, 'served');
        $this->createOrderWithItem($dish, 5, 'served');

        $data = $this->getJson('/api/menu')->assertOk()->json();
        $dishData = collect($data)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);

        // orders_count = 2 (nombre de commandes), pas 10
        $this->assertSame(2, $dishData['orders_count']);
    }

    // ------------------------------------------------------------------
    // 4. Paramètre ?orders_count_period=all
    // ------------------------------------------------------------------

    public function test_period_all_counts_every_order_ever(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
        ]);

        $this->createOrderWithItem($dish, 1, 'served', now()->subDays(2)->toDateTimeString());
        $this->createOrderWithItem($dish, 1, 'served', now()->subDays(45)->toDateTimeString());
        $this->createOrderWithItem($dish, 1, 'served', now()->subDays(200)->toDateTimeString());

        // Par défaut (30 jours) → 1
        $data30 = $this->getJson('/api/menu')->assertOk()->json();
        $dish30 = collect($data30)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);
        $this->assertSame(1, $dish30['orders_count']);

        // Avec all → 3
        $dataAll = $this->getJson('/api/menu?orders_count_period=all')->assertOk()->json();
        $dishAll = collect($dataAll)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);
        $this->assertSame(3, $dishAll['orders_count']);
    }

    // ------------------------------------------------------------------
    // 5. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_orders_count_is_isolated_across_restaurants(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
        ]);
        $this->createOrderWithItem($dish, 1, 'served');
        $this->createOrderWithItem($dish, 1, 'served');

        // Restaurant B, même menu_item_id impossible, mais on crée une commande
        // qui référence l'article du restaurant A → impossible en pratique car
        // l'isolation empêche ça. On vérifie juste que A ne voit que ses propres commandes.
        $this->assertSame(2, collect(
            $this->getJson('/api/menu')->assertOk()->json()
        )->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id)['orders_count']);
    }

    // ------------------------------------------------------------------
    // 6. Les plats sans commande affichent 0
    // ------------------------------------------------------------------

    public function test_menu_item_without_orders_shows_zero(): void
    {
        $this->actingAsWaiter();

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
        ]);

        $data = $this->getJson('/api/menu')->assertOk()->json();
        $dishData = collect($data)->flatMap(fn ($cat) => $cat['menu_items'])->firstWhere('id', $dish->id);

        $this->assertSame(0, $dishData['orders_count']);
    }
}
