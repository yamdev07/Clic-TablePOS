<?php

namespace Tests\Feature;

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
 * Config fiscale du restaurant (TVA, service, IFU, RCCM)
 * + fiche restaurant (en-tête des factures) et création des comptes caissier.
 */
class RestaurantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create(['name' => 'Clic&Table Café']);
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
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

    private function openOrderWithDish(): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'open',
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

    // ==================================================================
    // PARTIE 1 : Config fiscale (TVA, service, IFU, RCCM)
    // ==================================================================

    public function test_only_admin_and_manager_can_read_restaurant_settings(): void
    {
        foreach (['cashier', 'waiter', 'kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/restaurant/settings')->assertForbidden();
        }

        foreach (['admin', 'manager'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/restaurant/settings')->assertOk();
        }
    }

    public function test_only_admin_can_update_restaurant_settings(): void
    {
        $this->actingAsRole('manager');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertForbidden();

        $this->actingAsRole('cashier');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertForbidden();

        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertOk();
    }

    public function test_admin_can_update_fiscal_identity(): void
    {
        $this->actingAsRole('admin');

        $this->patchJson('/api/restaurant/settings', [
            'ifu' => '0201810034567',
            'rccm' => 'CI-ABJ-2022-B-12345',
            'phone' => '+225 07 88 12 34 56',
            'address' => 'Plateau, Rue du Commerce',
        ])->assertOk();

        $fresh = $this->restaurant->fresh();
        $this->assertSame('0201810034567', $fresh->ifu);
        $this->assertSame('CI-ABJ-2022-B-12345', $fresh->rccm);
        $this->assertSame('+225 07 88 12 34 56', $fresh->phone);
        $this->assertSame('Plateau, Rue du Commerce', $fresh->address);
    }

    public function test_admin_can_disable_tax_and_service(): void
    {
        $this->actingAsRole('admin');

        $this->patchJson('/api/restaurant/settings', [
            'tax_enabled' => false,
            'service_charge_enabled' => false,
        ])->assertOk();

        $fresh = $this->restaurant->fresh();
        $this->assertFalse($fresh->settings['tax_enabled']);
        $this->assertFalse($fresh->settings['service_charge_enabled']);
    }

    public function test_admin_cannot_set_invalid_payload(): void
    {
        $this->actingAsRole('admin');

        $this->patchJson('/api/restaurant/settings', ['ifu' => str_repeat('X', 100)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ifu');

        $this->patchJson('/api/restaurant/settings', ['tax_enabled' => 'oui'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_enabled');
    }

    public function test_disabled_tax_produces_zero_tax_on_new_orders(): void
    {
        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['tax_enabled' => false])->assertOk();

        $order = $this->openOrderWithDish();

        $this->assertSame(1000, $order->subtotal);
        $this->assertSame(0, $order->tax);
        $this->assertSame(50, $order->service_charge);
        $this->assertSame(1050, $order->total);
    }

    public function test_disabled_service_charge_produces_zero_service_on_new_orders(): void
    {
        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['service_charge_enabled' => false])->assertOk();

        $order = $this->openOrderWithDish();

        $this->assertSame(1000, $order->subtotal);
        $this->assertSame(180, $order->tax);
        $this->assertSame(0, $order->service_charge);
        $this->assertSame(1180, $order->total);
    }

    public function test_paid_orders_keep_their_historic_totals_when_tax_is_disabled_later(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->openOrderWithDish();
        $order->update(['status' => 'served']);

        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(1230, $fresh->total);
        $this->assertSame(180, $fresh->tax);
        $this->assertSame(50, $fresh->service_charge);

        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['tax_enabled' => false])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(1230, $fresh->total);
        $this->assertSame(180, $fresh->tax);
        $this->assertSame(50, $fresh->service_charge);
    }

    // ==================================================================
    // PARTIE 2 : Fiche restaurant (en-tête des factures) + comptes caissier
    // ==================================================================

    /** @test */
    public function any_staff_member_can_read_restaurant_details(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/restaurant')->assertOk()
            ->assertJsonPath('name', 'Clic&Table Café')
            ->assertJsonMissingPath('settings');
    }

    /** @test */
    public function admin_can_update_restaurant_details(): void
    {
        $this->actingAsRole('admin');

        $this->putJson('/api/restaurant', [
            'name' => 'Cactus Hôtel',
            'email' => 'contact@cactushotel.com',
            'phone' => '+229 00 00 00 00',
            'address' => 'Cotonou, Bénin',
        ])->assertOk()->assertJsonPath('name', 'Cactus Hôtel');

        $this->assertDatabaseHas('restaurants', ['id' => $this->restaurant->id, 'address' => 'Cotonou, Bénin']);
    }

    /** @test */
    public function non_admin_cannot_update_restaurant_details(): void
    {
        $this->actingAsRole('manager');

        $this->putJson('/api/restaurant', ['name' => 'X', 'email' => 'x@x.com'])->assertForbidden();
    }

    /** @test */
    public function restaurant_email_must_stay_unique(): void
    {
        Restaurant::factory()->create(['email' => 'pris@example.com']);
        $this->actingAsRole('admin');

        $this->putJson('/api/restaurant', ['name' => 'A', 'email' => 'pris@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    /** @test */
    public function admin_can_create_a_cashier_account(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Awa Caisse', 'email' => 'caisse@example.com', 'password' => 'secret123', 'role' => 'cashier',
        ])->assertCreated()->assertJsonPath('role', 'cashier');
    }

    /** @test */
    public function admin_cannot_create_a_platform_account(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'name' => 'Hack', 'email' => 'hack@example.com', 'password' => 'secret123', 'role' => 'super_admin',
        ])->assertUnprocessable();
    }
}
