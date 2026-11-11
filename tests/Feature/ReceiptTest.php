<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Restaurant;
use App\Models\RestaurantSupplement;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'name' => 'Chez Awa',
            'ifu' => '0201810034567',
            'rccm' => 'CI-ABJ-2022-B-12345',
            'phone' => '+225 07 88 12 34 56',
            'address' => 'Plateau, Rue du Commerce',
        ]);
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Yassa au Poulet Fermier',
            'price' => 5500,
        ]);
    }

    private function actingAsRole(string $role, ?Restaurant $restaurant = null): User
    {
        $user = User::factory()->create([
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'role' => $role,
            'name' => 'Test '.$role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function servedOrder(array $options = [], int $quantity = 1, ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;

        if ($restaurant->is($this->restaurant)) {
            $table = $this->table;
        } else {
            $table = Table::firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'number' => '9'],
                ['id' => (string) Str::uuid(), 'capacity' => 2, 'status' => 'occupied']
            );
        }

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'status' => 'served',
            'guest_count' => 2,
        ]);

        $optionsTotal = collect($options)->sum('price_delta') * $quantity;

        $item = OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => $quantity,
            'unit_price' => 5500,
            'total_price' => (5500 + collect($options)->sum('price_delta')) * $quantity,
            'options_total' => $optionsTotal,
            'kitchen_status' => 'served',
        ]);

        foreach ($options as $opt) {
            OrderItemOption::create([
                'id' => (string) Str::uuid(),
                'order_item_id' => $item->id,
                'source_type' => $opt['source_type'] ?? 'menu_option',
                'source_id' => null,
                'name' => $opt['name'],
                'price_delta' => $opt['price_delta'],
                'quantity' => 1,
            ]);
        }

        $order->recalculate();

        return $order->fresh();
    }

    private function pay(Order $order)
    {
        return $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Rôles
    // ------------------------------------------------------------------

    public function test_only_authorized_roles_can_view_a_receipt(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        $this->pay($order)->assertCreated();

        foreach (['kitchen'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/orders/{$order->id}/receipt")->assertForbidden();
        }

        foreach (['admin', 'manager', 'cashier', 'waiter'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/orders/{$order->id}/receipt")->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // 2. Contenu complet
    // ------------------------------------------------------------------

    public function test_a_paid_order_returns_a_complete_receipt(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();
        $this->pay($order)->assertCreated();

        $data = $this->getJson("/api/orders/{$order->id}/receipt")->assertOk()->json();

        // Restaurant
        $this->assertSame('Chez Awa', $data['restaurant']['name']);
        $this->assertSame('0201810034567', $data['restaurant']['ifu']);
        $this->assertSame('CI-ABJ-2022-B-12345', $data['restaurant']['rccm']);

        // Order
        $this->assertSame($order->order_number, $data['order']['order_number']);
        $this->assertSame('dine_in', $data['order']['type']);
        $this->assertSame('1', $data['order']['table']['number']);
        $this->assertSame(2, $data['order']['guest_count']);

        // Items
        $this->assertCount(1, $data['items']);
        $this->assertSame('Yassa au Poulet Fermier', $data['items'][0]['name']);
        $this->assertSame(5500, $data['items'][0]['unit_price']);
        $this->assertSame(6435, $data['items'][0]['line_total']); // 5500 + 18% + 5%

        // Totals
        $this->assertSame(5500, $data['totals']['subtotal']);
        $this->assertSame(990, $data['totals']['tax']);
        $this->assertSame(275, $data['totals']['service_charge']);
        $this->assertSame(6765, $data['totals']['total']);

        // Payments
        $this->assertCount(1, $data['payments']);
        $this->assertSame('cash', $data['payments'][0]['method']);
        $this->assertSame(6765, $data['payments'][0]['amount']);

        // Preview flag
        $this->assertFalse($data['preview']);
        $this->assertNotNull($data['receipt_number']);
        $this->assertNotNull($data['issued_at']);
    }

    // ------------------------------------------------------------------
    // 3. Commande non payée refusée
    // ------------------------------------------------------------------

    public function test_an_unpaid_order_is_refused_without_preview(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        $this->getJson("/api/orders/{$order->id}/receipt")->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 4. Preview autorisé
    // ------------------------------------------------------------------

    public function test_an_unpaid_order_returns_a_preview_with_flag(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder();

        $data = $this->getJson("/api/orders/{$order->id}/receipt?preview=1")->assertOk()->json();

        $this->assertTrue($data['preview']);
        $this->assertNull($data['receipt_number']); // pas encore facturé
        $this->assertSame(5500, $data['totals']['subtotal']);
    }

    // ------------------------------------------------------------------
    // 5. Isolation
    // ------------------------------------------------------------------

    public function test_receipt_is_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherOrder = $this->servedOrder([], 1, $other);

        $this->actingAsRole('cashier');
        $this->getJson("/api/orders/{$otherOrder->id}/receipt")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 6. Options et suppléments inclus
    // ------------------------------------------------------------------

    public function test_receipt_includes_options_and_supplements(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->servedOrder([
            ['name' => 'Non pimenté / Doux', 'price_delta' => 0],
            ['name' => 'Bissap Frais (50cl)', 'price_delta' => 1500, 'source_type' => 'menu_option'],
            ['name' => 'Portion Alloco croustillant', 'price_delta' => 1000, 'source_type' => 'supplement'],
        ]);
        $this->pay($order)->assertCreated();

        $data = $this->getJson("/api/orders/{$order->id}/receipt")->assertOk()->json();

        $options = collect($data['items'][0]['options'])->pluck('name')->all();
        $this->assertContains('Bissap Frais (50cl)', $options);
        $this->assertContains('Portion Alloco croustillant', $options);
        $this->assertContains('Non pimenté / Doux', $options);

        // line_total inclut les options : (5500 + 2500) * 1.23
        $this->assertSame(8000, $data['totals']['subtotal']);
    }

    // ------------------------------------------------------------------
    // 7. Numéro de facture séquentiel et figé
    // ------------------------------------------------------------------

    public function test_receipt_number_is_sequential_and_frozen(): void
    {
        $this->actingAsRole('cashier');

        // Commande 1
        $order1 = $this->servedOrder();
        $this->pay($order1)->assertCreated();
        $data1 = $this->getJson("/api/orders/{$order1->id}/receipt")->assertOk()->json();

        // Commande 2
        $order2 = $this->servedOrder();
        $this->pay($order2)->assertCreated();
        $data2 = $this->getJson("/api/orders/{$order2->id}/receipt")->assertOk()->json();

        // Un 2ᵉ appel sur la commande 1 renvoie le même numéro (figé)
        $data1bis = $this->getJson("/api/orders/{$order1->id}/receipt")->assertOk()->json();

        $today = now('Africa/Porto-Novo')->format('Ymd');
        $this->assertMatchesRegularExpression("/^FA-{$today}-\\d{4}$/", $data1['receipt_number']);
        $this->assertMatchesRegularExpression("/^FA-{$today}-\\d{4}$/", $data2['receipt_number']);
        $this->assertNotSame($data1['receipt_number'], $data2['receipt_number']);
        $this->assertSame($data1['receipt_number'], $data1bis['receipt_number']);

        // En base : 2 factures
        $this->assertSame(2, Invoice::count());
    }

    // ------------------------------------------------------------------
    // 8. Identité fiscale optionnelle
    // ------------------------------------------------------------------

    public function test_receipt_reflects_the_restaurant_fiscal_identity(): void
    {
        $this->actingAsRole('cashier');

        // Restaurant sans IFU ni RCCM
        $emptyRestaurant = Restaurant::factory()->create([
            'ifu' => null,
            'rccm' => null,
        ]);
        $this->actingAsRole('cashier', $emptyRestaurant);

        $table = Table::factory()->create([
            'restaurant_id' => $emptyRestaurant->id,
            'status' => 'occupied',
            'number' => '2',
        ]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $emptyRestaurant->id,
            'table_id' => $table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $emptyRestaurant->id])->id,
            'status' => 'served',
        ]);
        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 5500,
            'total_price' => 5500,
            'options_total' => 0,
            'kitchen_status' => 'served',
        ]);
        $order->recalculate();

        $this->pay($order)->assertCreated();
        $data = $this->getJson("/api/orders/{$order->id}/receipt")->assertOk()->json();

        $this->assertNull($data['restaurant']['ifu']);
        $this->assertNull($data['restaurant']['rccm']);
    }
}
