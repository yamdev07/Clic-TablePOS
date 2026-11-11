<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('menu_item_id');
            $table->string('type', 30); // required_single, optional_single, optional_multi
            $table->string('name', 100);
            $table->integer('price_delta')->default(0);
            $table->uuid('linked_menu_item_id')->nullable(); // pour les boissons
            $table->integer('display_order')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
            $table->foreign('linked_menu_item_id')->references('id')->on('menu_items')->nullOnDelete();
            $table->index(['menu_item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_options');
    }
};
