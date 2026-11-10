<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->uuid('table_id');
            $table->uuid('requested_by');
            $table->string('method', 20); // cash, mobile_money
            $table->string('operator', 20)->nullable();
            // Champs figés au moment de la création (copie depuis payment_numbers)
            $table->string('phone_number', 30)->nullable();
            $table->string('beneficiary_name', 100)->nullable();
            $table->string('status', 20)->default('pending'); // pending, validated, cancelled
            $table->uuid('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['table_id', 'status']);

            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->foreign('table_id')->references('id')->on('tables')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('validated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_requests');
    }
};
