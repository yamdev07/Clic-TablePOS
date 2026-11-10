<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le moyen de paiement n'est plus une liste fermée en base (cash, mobile_money, et l'historique)
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('operator', 20)->nullable()->after('method');
            $table->index(['operator', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['operator', 'reference']);
            $table->dropColumn('operator');
            // method reste un texte : revenir à l'enum ferait perdre les paiements Mobile Money
        });
    }
};
