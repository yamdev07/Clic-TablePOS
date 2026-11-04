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

class TableDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->admin = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_a_table_without_orders_can_be_deleted_and_the_deletion_is_logged(): void
    {
        $table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'status' => 'free',
        ]);

        $this->deleteJson("/api/tables/{$table->id}")->assertNoContent();

        $this->assertDatabaseMissing('tables', ['id' => $table->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'table.deleted', 'entity_id' => $table->id]);
    }

    public function test_a_table_with_orders_cannot_be_deleted_whatever_their_status(): void
    {
        foreach (['paid', 'cancelled', 'open', 'in_progress'] as $index => $status) {
            $table = Table::factory()->create([
                'restaurant_id' => $this->restaurant->id,
                'number' => (string) ($index + 1),
                'status' => 'free',
            ]);

            Order::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $this->restaurant->id,
                'table_id' => $table->id,
                'user_id' => $this->admin->id,
                'status' => $status,
            ]);

            $this->deleteJson("/api/tables/{$table->id}")
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $this->assertDatabaseHas('tables', ['id' => $table->id]);
            $this->assertDatabaseMissing('activity_logs', ['action' => 'table.deleted', 'entity_id' => $table->id]);
        }
    }
}
