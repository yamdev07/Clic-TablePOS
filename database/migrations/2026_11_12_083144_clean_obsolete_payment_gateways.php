<?php

use App\Models\Restaurant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Restaurant::query()->each(function (Restaurant $restaurant) {
            $gateways = $restaurant->payment_gateways ?? [];

            if (is_string($gateways)) {
                $gateways = json_decode($gateways, true) ?: [];
            }

            $cleaned = [
                'cash' => $gateways['cash'] ?? true,
                'mobile_money' => $gateways['mobile_money'] ?? true,
            ];

            $restaurant->update(['payment_gateways' => $cleaned]);
        });
    }

    public function down(): void
    {
        // Pas de rollback data
    }
};
