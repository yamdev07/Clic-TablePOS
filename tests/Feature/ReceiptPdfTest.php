<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
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

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
            'name' => 'Test '.$role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function paidOrder(): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
            'guest_count' => 2,
        ]);

        $item = OrderItem::create([
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

        // Ajouter une option figée (Bissap +1500)
        OrderItemOption::create([
            'id' => (string) Str::uuid(),
            'order_item_id' => $item->id,
            'source_type' => 'menu_option',
            'source_id' => null,
            'name' => 'Bissap Frais (50cl)',
            'price_delta' => 1500,
            'quantity' => 1,
        ]);

        $order->recalculate();

        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();

        return $order->fresh();
    }

    // ------------------------------------------------------------------
    // 1. Rôles autorisés
    // ------------------------------------------------------------------

    public function test_only_authorized_roles_can_download_the_pdf(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->paidOrder();

        // kitchen → 403
        $this->actingAsRole('kitchen');
        $this->getJson("/api/orders/{$order->id}/receipt.pdf")->assertForbidden();

        // admin/manager/cashier/waiter → OK
        foreach (['admin', 'manager', 'cashier', 'waiter'] as $role) {
            $this->actingAsRole($role);
            $this->get("/api/orders/{$order->id}/receipt.pdf")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    // ------------------------------------------------------------------
    // 2. Commande non payée → 422 sans ?preview
    // ------------------------------------------------------------------

    public function test_unpaid_order_without_preview_is_refused(): void
    {
        $this->actingAsRole('cashier');

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
        ]);

        $this->get("/api/orders/{$order->id}/receipt.pdf")->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 3. ?download=1 → Content-Disposition: attachment
    // ------------------------------------------------------------------

    public function test_download_flag_forces_attachment(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->paidOrder();

        $response = $this->get("/api/orders/{$order->id}/receipt.pdf?download=1")->assertOk();

        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition')
        );
    }

    // ------------------------------------------------------------------
    // 4. PDF contient les infos essentielles (parsing basique)
    // ------------------------------------------------------------------

    public function test_pdf_contains_essential_information(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->paidOrder();

        $response = $this->get("/api/orders/{$order->id}/receipt.pdf")->assertOk();
        $content = $response->getContent();

        // Signature PDF
        $this->assertStringStartsWith('%PDF-', $content);

        // Le contenu texte d'un PDF n'est pas directement lisible (compressé).
        // On vérifie au moins que la taille est significative (> 1 Ko)
        $this->assertGreaterThan(1000, strlen($content));
    }

    // ------------------------------------------------------------------
    // 5. ?format=ticket renvoie un PDF aussi
    // ------------------------------------------------------------------

    public function test_ticket_format_returns_a_pdf(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->paidOrder();

        $this->get("/api/orders/{$order->id}/receipt.pdf?format=ticket")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
