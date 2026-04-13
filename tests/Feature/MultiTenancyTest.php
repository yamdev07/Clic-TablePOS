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
}
