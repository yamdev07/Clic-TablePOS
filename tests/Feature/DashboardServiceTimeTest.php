<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardServiceTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_temps_de_service_d_un_plat_ajoute_apres_confirmation_part_de_son_created_at(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Africa/Porto-Novo']);
        $admin = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => 'admin',
        ]);
        $table = Table::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => 'occupied',
        ]);
        $menuItem = MenuItem::factory()->create(['restaurant_id' => $restaurant->id]);

        // 12h00 : la commande est confirmée
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Africa/Porto-Novo'));
        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => $admin->id,
            'status' => 'in_progress',
            'confirmed_at' => now(),
        ]);

        // 12h20 : un plat est ajouté après la confirmation
        $this->travelTo(Carbon::parse('2026-11-03 12:20:00', 'Africa/Porto-Novo'));
        $item = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'item_name' => $menuItem->name,
            'quantity' => 1,
            'unit_price' => 2000,
            'total_price' => 2000,
            'kitchen_status' => 'pending',
        ]);

        // 12h25 : le plat est prêt
        $this->travelTo(Carbon::parse('2026-11-03 12:25:00', 'Africa/Porto-Novo'));
        $item->forceFill(['kitchen_status' => 'ready', 'ready_at' => now()])->save();

        Sanctum::actingAs($admin);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('service.avg_wait_minutes', 5);
    }
}
