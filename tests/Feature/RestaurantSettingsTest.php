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

class RestaurantSettingsTest extends TestCase
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

    // ------------------------------------------------------------------
    // 1. Lecture : admin + manager
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 2. Écriture : admin uniquement
    // ------------------------------------------------------------------

    public function test_only_admin_can_update_restaurant_settings(): void
    {
        $this->actingAsRole('manager');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertForbidden();

        $this->actingAsRole('cashier');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertForbidden();

        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['ifu' => 'X'])->assertOk();
    }

    // ------------------------------------------------------------------
    // 3. Identité fiscale
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 4. Désactivation TVA et service
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 5. Validation des payloads invalides
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 6. TVA désactivée → tax = 0 sur les nouvelles commandes
    // ------------------------------------------------------------------

    public function test_disabled_tax_produces_zero_tax_on_new_orders(): void
    {
        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['tax_enabled' => false])->assertOk();

        $order = $this->openOrderWithDish();

        $this->assertSame(1000, $order->subtotal);
        $this->assertSame(0, $order->tax);
        $this->assertSame(50, $order->service_charge);   // 5 % actif
        $this->assertSame(1050, $order->total);
    }

    // ------------------------------------------------------------------
    // 7. Service désactivé → service = 0 sur les nouvelles commandes
    // ------------------------------------------------------------------

    public function test_disabled_service_charge_produces_zero_service_on_new_orders(): void
    {
        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['service_charge_enabled' => false])->assertOk();

        $order = $this->openOrderWithDish();

        $this->assertSame(1000, $order->subtotal);
        $this->assertSame(180, $order->tax);            // 18 % actif
        $this->assertSame(0, $order->service_charge);
        $this->assertSame(1180, $order->total);
    }

    // ------------------------------------------------------------------
    // 8. Commandes payées : montants figés malgré changement de config
    // ------------------------------------------------------------------

    public function test_paid_orders_keep_their_historic_totals_when_tax_is_disabled_later(): void
    {
        // 1. Commande servie + payée avec config par défaut (18 % + 5 %)
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

        // 2. L'admin désactive la TVA
        $this->actingAsRole('admin');
        $this->patchJson('/api/restaurant/settings', ['tax_enabled' => false])->assertOk();

        // 3. La commande payée est FIGÉE
        $fresh = $order->fresh();
        $this->assertSame(1230, $fresh->total);
        $this->assertSame(180, $fresh->tax);
        $this->assertSame(50, $fresh->service_charge);
    }
}
