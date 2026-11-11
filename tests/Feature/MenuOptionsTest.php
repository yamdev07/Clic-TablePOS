<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantSupplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $platsPrincipaux;

    private Category $grillades;

    private Category $boissons;

    private Category $entrees;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->platsPrincipaux = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plats Principaux',
            'slug' => 'plats-principaux',
        ]);
        $this->grillades = Category::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Grillades',
            'slug' => 'grillades',
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
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeDrink(string $name, int $price): MenuItem
    {
        return MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->boissons->id,
            'name' => $name,
            'price' => $price,
        ]);
    }

    private function makeDish(?Category $category = null): MenuItem
    {
        return MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => ($category ?? $this->platsPrincipaux)->id,
            'name' => 'Yassa au Poulet Fermier',
            'price' => 5500,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Créer des options sur un plat
    // ------------------------------------------------------------------

    public function test_admin_can_create_options_on_a_menu_item(): void
    {
        $this->actingAsAdmin();
        $dish = $this->makeDish();
        $bissap = $this->makeDrink('Bissap Frais', 1500);

        $response = $this->patchJson("/api/menu/{$dish->id}", [
            'options' => [
                ['type' => 'required_single', 'name' => 'Pimenté', 'price_delta' => 0],
                ['type' => 'required_single', 'name' => 'Non pimenté / Doux', 'price_delta' => 0],
                ['type' => 'optional_single', 'name' => 'Bissap Frais', 'price_delta' => 0, 'linked_menu_item_id' => $bissap->id],
            ],
        ])->assertOk();

        $this->assertSame(3, $dish->options()->count());
        $this->assertDatabaseHas('menu_item_options', [
            'menu_item_id' => $dish->id,
            'type' => 'required_single',
            'name' => 'Pimenté',
        ]);
        $this->assertDatabaseHas('menu_item_options', [
            'menu_item_id' => $dish->id,
            'type' => 'optional_single',
            'linked_menu_item_id' => $bissap->id,
        ]);
    }

    // ------------------------------------------------------------------
    // 2. Options remplacées à chaque PATCH
    // ------------------------------------------------------------------

    public function test_updating_options_replaces_the_previous_ones(): void
    {
        $this->actingAsAdmin();
        $dish = $this->makeDish();

        $this->patchJson("/api/menu/{$dish->id}", [
            'options' => [
                ['type' => 'required_single', 'name' => 'Pimenté', 'price_delta' => 0],
            ],
        ])->assertOk();
        $this->assertSame(1, $dish->options()->count());

        $this->patchJson("/api/menu/{$dish->id}", [
            'options' => [
                ['type' => 'required_single', 'name' => 'Pimenté', 'price_delta' => 0],
                ['type' => 'required_single', 'name' => 'Non pimenté / Doux', 'price_delta' => 0],
            ],
        ])->assertOk();
        $this->assertSame(2, $dish->options()->count());
    }

    // ------------------------------------------------------------------
    // 3. Plats principaux et grillades exigent au moins un required_single
    // ------------------------------------------------------------------

    public function test_plats_principaux_and_grillades_require_a_required_single_option(): void
    {
        $this->actingAsAdmin();

        foreach ([$this->platsPrincipaux, $this->grillades] as $category) {
            $dish = $this->makeDish($category);

            $this->patchJson("/api/menu/{$dish->id}", [
                'options' => [
                    ['type' => 'optional_single', 'name' => 'Boisson', 'price_delta' => 0],
                ],
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('menu_item_options', 0);
    }

    // ------------------------------------------------------------------
    // 4. Autres catégories : pas d'obligation
    // ------------------------------------------------------------------

    public function test_entrees_do_not_require_options(): void
    {
        $this->actingAsAdmin();
        $entree = $this->makeDish($this->entrees);

        // Pas de PATCH d'options du tout : c'est OK
        $this->assertSame(0, $entree->options()->count());

        // Créer une option optional_single sur une entrée est aussi OK
        $this->patchJson("/api/menu/{$entree->id}", [
            'options' => [
                ['type' => 'optional_single', 'name' => 'Sauce', 'price_delta' => 0],
            ],
        ])->assertOk();
    }

    // ------------------------------------------------------------------
    // 5. Isolation multi-tenant
    // ------------------------------------------------------------------

    public function test_options_are_isolated_across_restaurants(): void
    {
        $this->actingAsAdmin();
        $dish = $this->makeDish();

        $other = Restaurant::factory()->create();
        $otherCategory = Category::factory()->create([
            'restaurant_id' => $other->id,
            'slug' => 'plats-principaux',
        ]);
        $otherDish = MenuItem::factory()->create([
            'restaurant_id' => $other->id,
            'category_id' => $otherCategory->id,
        ]);

        // Un admin de `$this->restaurant` ne peut pas modifier l'autre plat
        $this->patchJson("/api/menu/{$otherDish->id}", [
            'options' => [
                ['type' => 'required_single', 'name' => 'Pimenté', 'price_delta' => 0],
            ],
        ])->assertNotFound();

        $this->assertDatabaseCount('menu_item_options', 0);
    }

    // ------------------------------------------------------------------
    // 6. Suppléments partagés : création, liste, suppression
    // ------------------------------------------------------------------

    public function test_admin_can_manage_shared_supplements(): void
    {
        $this->actingAsAdmin();

        // Création
        $r1 = $this->postJson('/api/supplements', [
            'name' => 'Portion Alloco croustillant',
            'price_delta' => 1000,
        ])->assertCreated();

        $r2 = $this->postJson('/api/supplements', [
            'name' => 'Sauce verte pimentée',
            'price_delta' => 500,
        ])->assertCreated();

        $this->assertSame(2, RestaurantSupplement::count());

        // Liste
        $list = $this->getJson('/api/supplements')->assertOk()->json('data');
        $this->assertCount(2, $list);

        // Suppression
        $this->deleteJson("/api/supplements/{$r1->json('id')}")->assertNoContent();
        $this->assertSame(1, RestaurantSupplement::count());
    }

    // ------------------------------------------------------------------
    // 7. Suppléments isolés entre restaurants
    // ------------------------------------------------------------------

    public function test_supplements_are_isolated_across_restaurants(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/supplements', ['name' => 'Alloco', 'price_delta' => 1000])->assertCreated();

        $other = Restaurant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $other->id,
            'role' => 'admin',
        ]));

        $list = $this->getJson('/api/supplements')->assertOk()->json('data');
        $this->assertCount(0, $list);
    }

    // ------------------------------------------------------------------
    // 8. Supplément : prix négatif refusé
    // ------------------------------------------------------------------

    public function test_supplement_price_must_be_positive(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/supplements', ['name' => 'X', 'price_delta' => -100])
            ->assertStatus(422);
    }
    // ------------------------------------------------------------------
    // 9. GET /api/menu/{item} expose les options
    // ------------------------------------------------------------------

    public function test_menu_item_show_exposes_its_options(): void
    {
        $this->actingAsAdmin();
        $dish = $this->makeDish();
        $bissap = $this->makeDrink('Bissap Frais', 1500);

        $this->patchJson("/api/menu/{$dish->id}", [
            'options' => [
                ['type' => 'required_single', 'name' => 'Pimenté', 'price_delta' => 0],
                ['type' => 'required_single', 'name' => 'Non pimenté / Doux', 'price_delta' => 0],
                ['type' => 'optional_single', 'name' => 'Bissap Frais', 'price_delta' => 0, 'linked_menu_item_id' => $bissap->id],
            ],
        ])->assertOk();

        $response = $this->getJson("/api/menu/{$dish->id}")->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('options', $data);
        $this->assertCount(3, $data['options']);

        $types = collect($data['options'])->pluck('type');
        $this->assertSame(2, $types->filter(fn ($t) => $t === 'required_single')->count());
        $this->assertSame(1, $types->filter(fn ($t) => $t === 'optional_single')->count());

        $bissapOption = collect($data['options'])->firstWhere('name', 'Bissap Frais');
        $this->assertSame($bissap->id, $bissapOption['linked_menu_item_id']);
    }
}
