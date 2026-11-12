<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanPaymentGatewaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_obsolete_payment_gateways_are_removed_from_existing_restaurants(): void
    {
        // Créer 3 restaurants avec les anciens moyens
        $r1 = Restaurant::factory()->create([
            'payment_gateways' => json_encode([
                'cash' => true,
                'wave' => false,
                'orange_money' => true,
                'free_money' => false,
            ]),
        ]);
        $r2 = Restaurant::factory()->create([
            'payment_gateways' => json_encode([
                'cash' => false,
                'wave' => true,
                'orange_money' => false,
            ]),
        ]);
        $r3 = Restaurant::factory()->create([
            'payment_gateways' => json_encode([
                'cash' => true,
                'mobile_money' => true,
            ]),
        ]);


        // Simuler l'état AVANT migration (les restaurants r1 et r2 ont les anciens moyens)
        // On applique la migration manuellement en appelant son up()
        $migrationFile = collect(glob(database_path('migrations/*_clean_obsolete_payment_gateways.php')))->first();
        $this->assertNotNull($migrationFile, 'La migration clean_obsolete_payment_gateways est introuvable');
        $migration = require $migrationFile;
        $migration->up();

        // Vérifier le résultat
        $fresh1 = $r1->fresh();
        $fresh2 = $r2->fresh();
        $fresh3 = $r3->fresh();


        // r1 : cash préservé, mobile_money ajouté à true, anciens moyens supprimés
        $this->assertSame(true, $fresh1->payment_gateways['cash']);
        $this->assertSame(true, $fresh1->payment_gateways['mobile_money']);
        $this->assertArrayNotHasKey('wave', $fresh1->payment_gateways);
        $this->assertArrayNotHasKey('orange_money', $fresh1->payment_gateways);
        $this->assertArrayNotHasKey('free_money', $fresh1->payment_gateways);

        // r2 : cash préservé (false)
        $this->assertSame(false, $fresh2->payment_gateways['cash']);
        $this->assertTrue($fresh2->payment_gateways['mobile_money']);
        $this->assertArrayNotHasKey('wave', $fresh2->payment_gateways);

        // r3 : déjà propre, inchangé
        $this->assertSame(true, $fresh3->payment_gateways['cash']);
        $this->assertSame(true, $fresh3->payment_gateways['mobile_money']);
        $this->assertArrayNotHasKey('wave', $fresh3->payment_gateways);
    }
}
