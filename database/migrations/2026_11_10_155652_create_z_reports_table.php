<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('z_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->date('date');
            $table->integer('sequence_number');
            $table->string('code', 20);
            $table->json('snapshot');
            $table->integer('cash_counted')->nullable();
            $table->integer('cash_difference')->nullable();
            $table->uuid('closed_by');
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['restaurant_id', 'date']);
            $table->unique(['restaurant_id', 'sequence_number']);
            $table->index(['restaurant_id', 'date']);

            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('z_reports');
    }
};
