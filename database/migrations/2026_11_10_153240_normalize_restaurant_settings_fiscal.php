<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $restaurants = DB::table('restaurants')->get(['id', 'settings']);

        foreach ($restaurants as $r) {
            $settings = json_decode($r->settings, true) ?: [];

            // Comportement actuel : TVA active et service 5 % actif
            $settings['tax_enabled'] = $settings['tax_enabled'] ?? true;
            $settings['service_charge_enabled'] = $settings['service_charge_enabled'] ?? true;

            // Les taux ne sont plus paramétrables : 18 % et 5 % sont codés en dur
            unset($settings['tax_rate'], $settings['service_charge']);

            DB::table('restaurants')->where('id', $r->id)->update([
                'settings' => json_encode($settings),
            ]);
        }
    }

    public function down(): void
    {
        // Pas de rollback data : on ne restaure pas tax_rate/service_charge
    }
};
