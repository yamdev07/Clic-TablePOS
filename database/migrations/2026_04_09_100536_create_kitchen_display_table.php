<?php
// database/migrations/2024_01_01_000009_create_kitchen_display_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_display', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->uuid('order_id');
            $table->jsonb('items')->default('[]');
            $table->enum('status', ['new', 'in_progress', 'completed'])->default('new');
            $table->integer('priority')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->foreign('restaurant_id')
                  ->references('id')
                  ->on('restaurants')
                  ->onDelete('cascade');
                  
            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');
                  
            $table->index(['restaurant_id', 'status', 'priority']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_display');
    }
};