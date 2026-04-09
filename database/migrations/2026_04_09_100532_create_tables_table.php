<?php
// database/migrations/2024_01_01_000005_create_tables_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->string('number');
            $table->string('name')->nullable();
            $table->integer('capacity')->default(4);
            $table->enum('status', ['free', 'occupied', 'reserved', 'dirty'])->default('free');
            $table->uuid('current_order_id')->nullable();
            $table->integer('x_position')->default(0);
            $table->integer('y_position')->default(0);
            $table->string('qr_code')->nullable();
            $table->jsonb('metadata')->default('[]');
            $table->timestamps();
            
            $table->foreign('restaurant_id')
                  ->references('id')
                  ->on('restaurants')
                  ->onDelete('cascade');
                  
            $table->unique(['restaurant_id', 'number']);
            $table->index(['restaurant_id', 'status']);
            $table->index('qr_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};