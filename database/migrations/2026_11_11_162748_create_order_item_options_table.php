<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_item_id');
            $table->string('source_type', 20); // 'menu_option' | 'supplement'
            $table->uuid('source_id')->nullable();
            $table->string('name', 100);
            $table->integer('price_delta');
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
