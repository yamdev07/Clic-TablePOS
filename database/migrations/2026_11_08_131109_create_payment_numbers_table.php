<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->string('operator', 20);
            $table->string('phone_number', 20);
            $table->string('beneficiary_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->onDelete('cascade');

            // Un même numéro ne s'enregistre qu'une fois par restaurant et par opérateur
            $table->unique(['restaurant_id', 'operator', 'phone_number']);
            $table->index(['restaurant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_numbers');
    }
};
