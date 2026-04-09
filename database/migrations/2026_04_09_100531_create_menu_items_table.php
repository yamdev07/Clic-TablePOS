<?php

// database/migrations/2024_01_01_000004_create_menu_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->uuid('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('price');
            $table->integer('cost')->nullable();
            $table->string('image')->nullable();
            $table->integer('preparation_time')->default(15);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_recommended')->default(false);
            $table->integer('display_order')->default(0);
            $table->jsonb('modifiers')->default('[]');
            $table->jsonb('taxes')->default('[]');
            $table->timestamps();

            $table->foreign('restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->onDelete('cascade');

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->onDelete('set null');

            $table->index(['restaurant_id', 'is_available', 'is_active']);
            $table->index(['restaurant_id', 'category_id']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
