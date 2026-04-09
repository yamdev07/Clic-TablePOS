<?php
// database/migrations/2024_01_01_000007_create_order_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('menu_item_id');
            $table->string('item_name');
            $table->text('item_description')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('unit_price');
            $table->integer('total_price');
            $table->enum('kitchen_status', ['pending', 'cooking', 'ready', 'served'])->default('pending');
            $table->text('special_instructions')->nullable();
            $table->jsonb('modifiers')->default('[]');
            $table->timestamps();
            
            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');
                  
            $table->foreign('menu_item_id')
                  ->references('id')
                  ->on('menu_items');
                  
            $table->index(['order_id', 'kitchen_status']);
            $table->index('kitchen_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};