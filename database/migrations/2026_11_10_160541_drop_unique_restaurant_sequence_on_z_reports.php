<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('z_reports', function (Blueprint $table) {
            $table->dropUnique(['restaurant_id', 'sequence_number']);
            $table->unique(['restaurant_id', 'date', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('z_reports', function (Blueprint $table) {
            $table->dropUnique(['restaurant_id', 'date', 'sequence_number']);
            $table->unique(['restaurant_id', 'sequence_number']);
        });
    }
};
