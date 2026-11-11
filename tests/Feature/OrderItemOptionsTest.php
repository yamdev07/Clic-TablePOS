<?php

namespace Tests\Feature;

use App\Models\Category;
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

class OrderItemOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private Category $platsPrincipaux;

    private Category $boissons;

    private Category $entrees;

    private MenuItem $yassa;

    private MenuItem $bissap;

    private MenuItemOption $pimente;

    private MenuItemOption $nonPimente;

    private MenuItemOption $optionBissap;

    private RestaurantSupplement $alloco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);

        $this->platsPrincipaux = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plats Principaux',
            'slug' => 'plats-principaux',
        ]);
        $this->boissons = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Boissons',
            'slug' => 'boissons',
        ]);
        $this->entrees = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Entrées',
            'slug' => 'entrees',
        ]);

        $this->yassa = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->platsPrincipaux->id,
            'name' => 'Yassa au Poulet Fermier',
            'price' => 5500,
        ]);
        $this->bissap = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->boissons->id,
            'name' => 'Bissap Frais à la Menthe (50cl)',
            'price' => 1500,
        ]);

        $this->pimente = MenuItemOption::create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->yassa->id,
            'type' => 'required_single',
            'name' => 'Pimenté',
            'price_delta' => 0,
            'is_available' => true,
        ]);
        $this->nonPimente = MenuItemOption::create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->yassa->id,
            'type' => 'required_single',
            'name' => 'Non pimenté / Doux',
            'price_delta' => 0,
            'is_available' => true,
        ]);
        $this->optionBissap = MenuItemOption::create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->yassa->id,
            'type' => 'optional_single',
            'name' => 'Bissap Frais à la Menthe (50cl)',
            'price_delta' => 0,
            'linked_menu_item_id' => $this->bissap->id,
            'is_available' => true,
        ]);

        $this->alloco = RestaurantSupplement::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Portion Alloco croustillant',
            'price_delta' => 1000,
            'is_available' => true,
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

    private function makeOrder(): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'open',
        ]);
    }

    private function addItem(Order $order, array $payload)
    {
        return $this->postJson("/api/orders/{$order->id}/items", $payload);
    }

    // ------------------------------------------------------------------
    // 1. required_single OK
    // ------------------------------------------------------------------

    public function test_adding_an_item_with_required_single_option(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
            ],
        ])->assertCreated();

        $itemId = $response->json('data.id');
        $this->assertSame(5500, OrderItem::find($itemId)->total_price);
        $this->assertSame(5500, OrderItem::find($itemId)->unit_price);
        $this->assertSame(0, OrderItem::find($itemId)->options_total);
        $this->assertSame(1, OrderItemOption::where('order_item_id', $itemId)->count());
    }

    // ------------------------------------------------------------------
    // 2. required_single manquant → 422
    // ------------------------------------------------------------------

    public function test_adding_an_item_without_required_single_option_is_refused(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
        ])->assertStatus(422);

        $this->assertDatabaseCount('order_items', 0);
    }

    // ------------------------------------------------------------------
    // 3. Boisson optionnelle (1 seule autorisée)
    // ------------------------------------------------------------------

    public function test_adding_an_item_with_one_optional_boisson(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
                ['menu_item_option_id' => $this->optionBissap->id],
            ],
        ])->assertCreated();

        $item = OrderItem::find($response->json('data.id'));
        $this->assertSame(5500 + 1500, $item->total_price);  // 7000
        $this->assertSame(1500, $item->options_total);
        $this->assertSame(2, OrderItemOption::where('order_item_id', $item->id)->count());
    }

    // ------------------------------------------------------------------
    // 4. Deux optional_single → 422
    // ------------------------------------------------------------------

    public function test_adding_an_item_with_two_optional_single_is_refused(): void
    {
        // Une 2ᵉ boisson pour tester
        $coca = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->boissons->id,
            'name' => 'Coca-Cola',
            'price' => 1000,
        ]);
        $optionCoca = MenuItemOption::create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $this->yassa->id,
            'type' => 'optional_single',
            'name' => 'Coca-Cola',
            'price_delta' => 0,
            'linked_menu_item_id' => $coca->id,
            'is_available' => true,
        ]);

        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
                ['menu_item_option_id' => $this->optionBissap->id],
                ['menu_item_option_id' => $optionCoca->id],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('order_items', 0);
    }

    // ------------------------------------------------------------------
    // 5. Multi suppléments
    // ------------------------------------------------------------------

    public function test_adding_an_item_with_multiple_supplements(): void
    {
        $sauce = RestaurantSupplement::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Sauce verte pimentée',
            'price_delta' => 500,
            'is_available' => true,
        ]);

        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
            ],
            'supplements' => [
                ['restaurant_supplement_id' => $this->alloco->id],
                ['restaurant_supplement_id' => $sauce->id],
            ],
        ])->assertCreated();

        $item = OrderItem::find($response->json('data.id'));
        $this->assertSame(5500 + 1000 + 500, $item->total_price); // 7000
        $this->assertSame(1500, $item->options_total);
    }

    // ------------------------------------------------------------------
    // 6. Suppléments interdits sur les entrées
    // ------------------------------------------------------------------

    public function test_supplement_only_allowed_for_plats_principaux_and_grillades(): void
    {
        $entree = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->entrees->id,
            'price' => 3000,
        ]);

        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $this->addItem($order, [
            'menu_item_id' => $entree->id,
            'quantity' => 1,
            'supplements' => [
                ['restaurant_supplement_id' => $this->alloco->id],
            ],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 7. Figement des options après création
    // ------------------------------------------------------------------

    public function test_options_are_frozen_after_creation(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
                ['menu_item_option_id' => $this->optionBissap->id],
            ],
        ])->assertCreated();

        $item = OrderItem::find($response->json('data.id'));

        // Après l'ajout, on modifie le prix de la boisson source
        $this->bissap->update(['price' => 2500]);

        // L'item garde son prix figé
        $this->assertSame(7000, $item->fresh()->total_price);
        $this->assertSame(1500, $item->fresh()->options_total);
    }

    // ------------------------------------------------------------------
    // 8. Option d'un autre restaurant → 422
    // ------------------------------------------------------------------

    public function test_options_are_isolated_across_restaurants(): void
    {
        $other = Restaurant::factory()->create();
        $otherCategory = Category::factory()->create([
            'restaurant_id' => $other->id,
            'slug' => 'plats-principaux',
        ]);
        $otherDish = MenuItem::factory()->create([
            'restaurant_id' => $other->id,
            'category_id' => $otherCategory->id,
        ]);
        $otherOption = MenuItemOption::create([
            'id' => (string) Str::uuid(),
            'menu_item_id' => $otherDish->id,
            'type' => 'required_single',
            'name' => 'Pimenté',
            'price_delta' => 0,
            'is_available' => true,
        ]);

        $this->actingAsWaiter();
        $order = $this->makeOrder();

        // On essaie d'ajouter le plat A avec l'option du restaurant B → 422
        $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $otherOption->id],
            ],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 9. recalculate() prend en compte les options
    // ------------------------------------------------------------------

    public function test_recalculate_takes_options_into_account(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
                ['menu_item_option_id' => $this->optionBissap->id],
            ],
            'supplements' => [
                ['restaurant_supplement_id' => $this->alloco->id],
            ],
        ])->assertCreated();

        $fresh = $order->fresh();
        $this->assertSame(5500 + 1500 + 1000, $fresh->subtotal); // 8000
        $this->assertSame((int) (8000 * 0.18), $fresh->tax);
        $this->assertSame((int) (8000 * 0.05), $fresh->service_charge);
        $this->assertSame(8000 + 1440 + 400, $fresh->total);
    }

    // ------------------------------------------------------------------
    // 10. Quantité multiplie les options
    // ------------------------------------------------------------------

    public function test_quantity_multiplies_options(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 2,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
            ],
            'supplements' => [
                ['restaurant_supplement_id' => $this->alloco->id],
            ],
        ])->assertCreated();

        $item = OrderItem::find($response->json('data.id'));
        // (5500 + 1000) * 2 = 13000
        $this->assertSame(13000, $item->total_price);
        $this->assertSame(2000, $item->options_total); // 1000 × 2
    }

    // ------------------------------------------------------------------
    // 11. Prix des boissons liées figé à l'ajout
    // ------------------------------------------------------------------

    public function test_linked_option_price_is_frozen_in_order_item(): void
    {
        $this->actingAsWaiter();
        $order = $this->makeOrder();

        $response = $this->addItem($order, [
            'menu_item_id' => $this->yassa->id,
            'quantity' => 1,
            'options' => [
                ['menu_item_option_id' => $this->nonPimente->id],
                ['menu_item_option_id' => $this->optionBissap->id],
            ],
        ])->assertCreated();

        // Le prix figé de l'option Bissap doit être 1500 (le prix source actuel)
        $bissapOption = OrderItemOption::where('order_item_id', $response->json('data.id'))
            ->where('name', 'Bissap Frais à la Menthe (50cl)')
            ->first();
        $this->assertNotNull($bissapOption);
        $this->assertSame(1500, $bissapOption->price_delta);

        // Modifier le prix source ne change pas
        $this->bissap->update(['price' => 3000]);
        $bissapOption = $bissapOption->fresh();
        $this->assertSame(1500, $bissapOption->price_delta);
    }
}
