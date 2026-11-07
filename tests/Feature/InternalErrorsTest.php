<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une erreur inattendue ne doit jamais révéler son message, son fichier ni sa ligne au client.
 */
class InternalErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SQLSTATE secret-detail';

    private const GENERIC = 'Une erreur interne est survenue.';

    private Restaurant $restaurant;

    private User $admin;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->admin = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function openOrder(): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
        ]);
    }

    /** À appeler après avoir préparé les données : toute écriture sur une commande échoue ensuite. */
    private function makeOrderWritesFail(): void
    {
        Order::creating(fn () => throw new \RuntimeException(self::SECRET));
        Order::updating(fn () => throw new \RuntimeException(self::SECRET));
    }

    private function assertGenericError(TestResponse $response): void
    {
        $response->assertStatus(500);

        $this->assertStringNotContainsString('secret-detail', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());

        $json = $response->json();
        $this->assertArrayNotHasKey('file', $json);
        $this->assertArrayNotHasKey('line', $json);
        $this->assertSame(self::GENERIC, $json['message']);
    }

    public function test_login_does_not_leak_exception_details(): void
    {
        User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'email' => 'awa@example.com',
        ]);
        Hash::shouldReceive('check')->andThrow(new \RuntimeException(self::SECRET));

        $this->assertGenericError(
            $this->postJson('/api/login', ['email' => 'awa@example.com', 'password' => 'Motdepasse1'])
        );
    }

    public function test_creating_an_order_does_not_leak_exception_details(): void
    {
        $this->makeOrderWritesFail();

        $this->assertGenericError(
            $this->postJson('/api/orders', ['table_id' => $this->table->id])
        );
    }

    public function test_adding_an_item_does_not_leak_exception_details(): void
    {
        $order = $this->openOrder();
        $this->makeOrderWritesFail();

        $this->assertGenericError(
            $this->postJson("/api/orders/{$order->id}/items", [
                'menu_item_id' => $this->dish->id,
                'quantity' => 1,
            ])
        );
    }

    public function test_removing_an_item_does_not_leak_exception_details(): void
    {
        $order = $this->openOrder();
        $item = $order->items()->create([
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);
        $order->recalculate();
        $this->makeOrderWritesFail();

        $this->assertGenericError(
            $this->deleteJson("/api/orders/{$order->id}/items/{$item->id}")
        );
    }

    public function test_confirming_an_order_does_not_leak_exception_details(): void
    {
        $order = $this->openOrder();
        $this->makeOrderWritesFail();

        $this->assertGenericError($this->postJson("/api/orders/{$order->id}/confirm"));
    }

    public function test_sending_to_the_kitchen_does_not_leak_exception_details(): void
    {
        $order = $this->openOrder();
        $this->makeOrderWritesFail();

        $this->assertGenericError($this->postJson("/api/orders/{$order->id}/send-to-kitchen"));
    }

    public function test_login_validation_errors_are_reported_as_422_not_500(): void
    {
        $this->postJson('/api/login', ['password' => 'Motdepasse1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
