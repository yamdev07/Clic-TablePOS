<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vérifie que les données d'un restaurant ne sont JAMAIS
 * visibles depuis un autre restaurant (isolation multi-tenant).
 */
class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;
    private Restaurant $restaurantB;
    private User       $userA;
    private User       $userB;
    private Table      $tableA;
    private Table      $tableB;
    private Order      $orderA;
    private Order      $orderB;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Restaurant A ──────────────────────────────────────────
        $this->restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A']);

        $this->userA = User::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
            'role'          => 'admin',
        ]);

        $this->tableA = Table::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
            'number'        => '1',
            'status'        => 'occupied',
        ]);

        $this->orderA = Order::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $this->restaurantA->id,
            'table_id'      => $this->tableA->id,
            'user_id'       => $this->userA->id,
            'status'        => 'in_progress',
            'total'         => 5000,
            'due_amount'    => 5000,
        ]);

        // ── Restaurant B ──────────────────────────────────────────
        $this->restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B']);

        $this->userB = User::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'role'          => 'admin',
        ]);

        $this->tableB = Table::factory()->create([
            'restaurant_id' => $this->restaurantB->id,
            'number'        => '1',
            'status'        => 'occupied',
        ]);

        $this->orderB = Order::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $this->restaurantB->id,
            'table_id'      => $this->tableB->id,
            'user_id'       => $this->userB->id,
            'status'        => 'in_progress',
            'total'         => 8000,
            'due_amount'    => 8000,
        ]);
    }

    /** @test */
    public function user_only_sees_own_restaurant_orders(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/orders');
        $response->assertStatus(200);

        $ids = collect($response->json('data') ?? $response->json())
            ->pluck('id');

        $this->assertTrue($ids->contains($this->orderA->id),  'Order A manquante');
        $this->assertFalse($ids->contains($this->orderB->id), 'Order B ne doit pas apparaître');
    }

    /** @test */
    public function user_only_sees_own_restaurant_tables(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/tables');
        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id');

        $this->assertTrue($ids->contains($this->tableA->id),  'Table A manquante');
        $this->assertFalse($ids->contains($this->tableB->id), 'Table B ne doit pas apparaître');
    }

    /** @test */
    public function user_cannot_add_payment_to_another_restaurants_order(): void
    {
        // userA essaie de payer la commande du restaurant B
        Sanctum::actingAs($this->userA);

        $response = $this->postJson("/api/orders/{$this->orderB->id}/payments", [
            'amount' => 8000,
            'method' => 'cash',
        ]);

        // 404 ou 403 — dans tous les cas pas 201
        $response->assertStatus(404);
    }

    /** @test */
    public function user_cannot_cancel_another_restaurants_order(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson("/api/orders/{$this->orderB->id}/cancel");

        $response->assertStatus(404);
    }

    /** @test */
    public function stats_only_reflect_own_restaurant(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/stats');
        $response->assertStatus(200);

        // Le restaurant A a 1 table occupée, pas 2
        $this->assertEquals(1, $response->json('tables'));
        $this->assertEquals(1, $response->json('occupiedTables'));
    }

    // ── Accès par ID aux ressources d'un autre restaurant ─────────────────

    /** @test */
    public function user_cannot_access_or_modify_another_restaurants_table(): void
    {
        Sanctum::actingAs($this->userA);
        $id = $this->tableB->id;

        $this->getJson("/api/tables/{$id}")->assertNotFound();
        $this->putJson("/api/tables/{$id}", ['capacity' => 2])->assertNotFound();
        $this->patchJson("/api/tables/{$id}/status", ['status' => 'free'])->assertNotFound();
        $this->deleteJson("/api/tables/{$id}")->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $id, 'status' => 'occupied']);
    }

    /** @test */
    public function user_cannot_access_or_modify_another_restaurants_menu_item(): void
    {
        $itemB = MenuItem::factory()->create(['restaurant_id' => $this->restaurantB->id]);
        Sanctum::actingAs($this->userA);

        $this->getJson("/api/menu/{$itemB->id}")->assertNotFound();
        $this->putJson("/api/menu/{$itemB->id}", ['price' => 1])->assertNotFound();
        $this->patchJson("/api/menu/{$itemB->id}/availability")->assertNotFound();
        $this->deleteJson("/api/menu/{$itemB->id}")->assertNotFound();

        $this->assertDatabaseHas('menu_items', ['id' => $itemB->id, 'price' => $itemB->price]);
    }

    /** @test */
    public function user_cannot_modify_another_restaurants_category(): void
    {
        $categoryB = Category::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $this->restaurantB->id,
            'name'          => 'Boissons B',
            'slug'          => 'boissons-b',
        ]);
        Sanctum::actingAs($this->userA);

        $this->putJson("/api/categories/{$categoryB->id}", ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson("/api/categories/{$categoryB->id}")->assertNotFound();

        // Ni rattacher un plat à une catégorie étrangère
        $this->postJson('/api/menu', [
            'name' => 'Plat', 'price' => 1000, 'category_id' => $categoryB->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('categories', ['id' => $categoryB->id, 'name' => 'Boissons B']);
    }

    /** @test */
    public function admin_cannot_modify_another_restaurants_user(): void
    {
        Sanctum::actingAs($this->userA);

        $this->putJson("/api/users/{$this->userB->id}", ['role' => 'waiter'])->assertNotFound();
        $this->deleteJson("/api/users/{$this->userB->id}")->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $this->userB->id, 'role' => 'admin', 'is_active' => true]);
    }

    /** @test */
    public function kitchen_cannot_change_another_restaurants_order_item(): void
    {
        $menuItemB = MenuItem::factory()->create(['restaurant_id' => $this->restaurantB->id]);
        $itemB = $this->orderB->items()->create([
            'id'             => (string) Str::uuid(),
            'menu_item_id'   => $menuItemB->id,
            'item_name'      => 'Plat B',
            'quantity'       => 1,
            'unit_price'     => 1000,
            'total_price'    => 1000,
            'kitchen_status' => 'pending',
        ]);
        Sanctum::actingAs($this->userA);

        foreach (['cooking', 'ready', 'serve', 'rupture'] as $action) {
            $this->patchJson("/api/kitchen/items/{$itemB->id}/{$action}")->assertNotFound();
        }

        $this->assertDatabaseHas('order_items', ['id' => $itemB->id, 'kitchen_status' => 'pending']);
    }

    /** @test */
    public function user_cannot_open_order_on_another_restaurants_table(): void
    {
        $this->tableB->update(['status' => 'free']);
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/orders', ['table_id' => $this->tableB->id])->assertUnprocessable();

        $this->assertDatabaseHas('tables', ['id' => $this->tableB->id, 'status' => 'free']);
    }

    /** @test */
    public function user_cannot_add_another_restaurants_menu_item_to_order(): void
    {
        $itemB = MenuItem::factory()->create(['restaurant_id' => $this->restaurantB->id]);
        Sanctum::actingAs($this->userA);

        $this->postJson("/api/orders/{$this->orderA->id}/items", ['menu_item_id' => $itemB->id])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('order_items', ['menu_item_id' => $itemB->id]);
    }
}
