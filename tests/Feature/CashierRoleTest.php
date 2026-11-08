<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierRoleTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
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

    public function test_an_admin_can_create_a_cashier_and_turn_a_waiter_into_one(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Marie Caissière',
            'email' => 'marie@example.com',
            'password' => 'Motdepasse1',
            'role' => 'cashier',
        ])->assertCreated()->assertJsonPath('role', 'cashier');

        $waiter = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);

        $this->putJson("/api/users/{$waiter->id}", ['role' => 'cashier'])
            ->assertOk()
            ->assertJsonPath('role', 'cashier');

        $this->assertSame('cashier', $waiter->fresh()->role);
    }

    public function test_a_cashier_cannot_use_the_dashboard_the_kitchen_or_cancellation(): void
    {
        $this->actingAsRole('cashier');
        $table = Table::factory()->create(['restaurant_id' => $this->restaurant->id]);
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'in_progress',
        ]);

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/kitchen/pending')->assertForbidden();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertForbidden();

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_a_cashier_can_read_orders_and_tables(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/orders')->assertOk();
        $this->getJson('/api/tables')->assertOk();
    }
}
