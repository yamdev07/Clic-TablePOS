<?php

namespace Tests\Feature;

use App\Models\Category;
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

/**
 * Un admin du restaurant A ne doit pouvoir ni lire, ni modifier,
 * ni supprimer les données du restaurant B, même en connaissant leurs ids.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    private User $adminA;

    private User $waiterB;

    private Table $tableA;

    private Table $tableB;

    private MenuItem $menuItemB;

    private Order $orderA;

    private Order $orderB;

    private OrderItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurantA = Restaurant::factory()->create();
        $this->restaurantB = Restaurant::factory()->create();

        $this->adminA = User::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
            'role' => 'admin',
        ]);

        $this->waiterB = User::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'role' => 'waiter',
        ]);

        $this->tableA = Table::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
            'status' => 'free',
        ]);

        $this->tableB = Table::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'number' => '1',
            'status' => 'free',
        ]);

        $this->menuItemB = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'name' => 'Plat du restaurant B',
            'is_available' => true,
        ]);

        $this->orderA = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurantA->id,
            'table_id' => $this->tableA->id,
            'user_id' => $this->adminA->id,
            'status' => 'open',
        ]);

        $this->orderB = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurantB->id,
            'table_id' => $this->tableB->id,
            'user_id' => $this->waiterB->id,
            'status' => 'in_progress',
        ]);

        $this->itemB = OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $this->orderB->id,
            'menu_item_id' => $this->menuItemB->id,
            'item_name' => 'Plat du restaurant B',
            'quantity' => 1,
            'unit_price' => 3000,
            'total_price' => 3000,
            'kitchen_status' => 'pending',
        ]);

        Sanctum::actingAs($this->adminA);
    }

    // ── Contrôle : les routes fonctionnent bien sur son propre restaurant ──

    public function test_admin_can_update_own_table_status(): void
    {
        $this->patchJson("/api/tables/{$this->tableA->id}/status", ['status' => 'dirty'])
            ->assertOk();
    }

    // ── Tables ──

    public function test_cannot_view_other_restaurant_table(): void
    {
        $this->getJson("/api/tables/{$this->tableB->id}")->assertNotFound();
    }

    public function test_cannot_update_other_restaurant_table(): void
    {
        $this->putJson("/api/tables/{$this->tableB->id}", ['number' => '99'])
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $this->tableB->id, 'number' => '1']);
    }

    public function test_cannot_change_status_of_other_restaurant_table(): void
    {
        $this->patchJson("/api/tables/{$this->tableB->id}/status", ['status' => 'dirty'])
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $this->tableB->id, 'status' => 'free']);
    }

    public function test_cannot_delete_other_restaurant_table(): void
    {
        $this->deleteJson("/api/tables/{$this->tableB->id}")->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $this->tableB->id]);
    }

    // ── Menu ──

    public function test_cannot_toggle_availability_of_other_restaurant_menu_item(): void
    {
        $this->patchJson("/api/menu/{$this->menuItemB->id}/availability")->assertNotFound();

        $this->assertDatabaseHas('menu_items', ['id' => $this->menuItemB->id, 'is_available' => true]);
    }

    public function test_cannot_delete_other_restaurant_menu_item(): void
    {
        $this->deleteJson("/api/menu/{$this->menuItemB->id}")->assertNotFound();

        $this->assertDatabaseHas('menu_items', ['id' => $this->menuItemB->id]);
    }

    // ── Utilisateurs ──

    public function test_cannot_change_password_of_other_restaurant_user(): void
    {
        $oldHash = $this->waiterB->password;

        $this->putJson("/api/users/{$this->waiterB->id}", ['password' => 'piratage123'])
            ->assertNotFound();

        $this->assertSame($oldHash, $this->waiterB->fresh()->password);
    }

    public function test_cannot_deactivate_other_restaurant_user(): void
    {
        $this->deleteJson("/api/users/{$this->waiterB->id}")->assertNotFound();

        $this->assertTrue((bool) $this->waiterB->fresh()->is_active);
    }

    // ── Cuisine ──

    public function test_cannot_update_kitchen_status_of_other_restaurant_item(): void
    {
        $this->patchJson("/api/kitchen/items/{$this->itemB->id}/cooking")->assertNotFound();
        $this->patchJson("/api/kitchen/items/{$this->itemB->id}/ready")->assertNotFound();
        $this->patchJson("/api/kitchen/items/{$this->itemB->id}/serve")->assertNotFound();

        $this->assertDatabaseHas('order_items', ['id' => $this->itemB->id, 'kitchen_status' => 'pending']);
    }

    public function test_cannot_mark_other_restaurant_menu_item_out_of_stock(): void
    {
        $this->patchJson("/api/kitchen/items/{$this->itemB->id}/rupture")->assertNotFound();

        $this->assertDatabaseHas('menu_items', ['id' => $this->menuItemB->id, 'is_available' => true]);
    }

    // ── Commandes : ressources d'un autre restaurant envoyées dans le corps de la requête ──

    public function test_cannot_create_order_on_other_restaurant_table(): void
    {
        $response = $this->postJson('/api/orders', ['table_id' => $this->tableB->id]);

        $this->assertContains($response->getStatusCode(), [404, 422]);
        $this->assertDatabaseHas('tables', ['id' => $this->tableB->id, 'status' => 'free']);
    }

    public function test_cannot_add_other_restaurant_menu_item_to_order(): void
    {
        $response = $this->postJson("/api/orders/{$this->orderA->id}/items", [
            'menu_item_id' => $this->menuItemB->id,
            'quantity' => 1,
        ]);

        $this->assertContains($response->getStatusCode(), [404, 422]);
        $this->assertDatabaseMissing('order_items', [
            'order_id' => $this->orderA->id,
            'menu_item_id' => $this->menuItemB->id,
        ]);
    }

    private function makeCategory(Restaurant $restaurant, string $name): Category
    {
        return Category::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'display_order' => 0,
            'is_active' => true,
        ]);
    }

    // ── Menu : routes que tu n'avais pas encore testées ──

    public function test_can_view_own_menu_item(): void
    {
        $menuItemA = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
        ]);

        $this->getJson("/api/menu/{$menuItemA->id}")->assertOk();
    }

    public function test_cannot_view_other_restaurant_menu_item(): void
    {
        $this->getJson("/api/menu/{$this->menuItemB->id}")->assertNotFound();
    }

    public function test_cannot_create_menu_item_in_other_restaurant_category(): void
    {
        $categoryB = $this->makeCategory($this->restaurantB, 'Boissons B');

        $this->postJson('/api/menu', [
            'name' => 'Plat piraté',
            'price' => 1000,
            'category_id' => $categoryB->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('menu_items', ['name' => 'Plat piraté']);
    }

    public function test_cannot_move_menu_item_to_other_restaurant_category(): void
    {
        $menuItemA = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
        ]);
        $categoryB = $this->makeCategory($this->restaurantB, 'Boissons B');

        $this->putJson("/api/menu/{$menuItemA->id}", ['category_id' => $categoryB->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('menu_items', [
            'id' => $menuItemA->id,
            'category_id' => $categoryB->id,
        ]);
    }

    // ── Catégories ──

    public function test_can_update_and_delete_own_category(): void
    {
        $categoryA = $this->makeCategory($this->restaurantA, 'Entrées A');

        $this->putJson("/api/categories/{$categoryA->id}", ['name' => 'Entrées'])
            ->assertOk();

        $this->deleteJson("/api/categories/{$categoryA->id}")->assertNoContent();
    }

    public function test_cannot_update_other_restaurant_category(): void
    {
        $categoryB = $this->makeCategory($this->restaurantB, 'Boissons B');

        $this->putJson("/api/categories/{$categoryB->id}", ['name' => 'Piraté'])
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $categoryB->id, 'name' => 'Boissons B']);
    }

    public function test_cannot_delete_other_restaurant_category(): void
    {
        $categoryB = $this->makeCategory($this->restaurantB, 'Boissons B');

        $this->deleteJson("/api/categories/{$categoryB->id}")->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $categoryB->id]);
    }

    public function test_other_restaurant_category_with_items_gives_404_not_422(): void
    {
        $categoryB = $this->makeCategory($this->restaurantB, 'Boissons B');
        MenuItem::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'category_id' => $categoryB->id,
        ]);

        // Un 422 révélerait que cette catégorie existe et contient des plats
        $this->deleteJson("/api/categories/{$categoryB->id}")->assertNotFound();
    }
}
