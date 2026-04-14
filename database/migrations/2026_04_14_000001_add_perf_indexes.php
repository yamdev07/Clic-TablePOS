<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes de performance manquants identifiés par analyse des requêtes lentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // categories.restaurant_id — filtre dans MenuController::index
        Schema::table('categories', function (Blueprint $table) {
            if (! $this->indexExists('categories', 'categories_restaurant_id_is_active_index')) {
                $table->index(['restaurant_id', 'is_active', 'display_order'], 'categories_restaurant_id_is_active_index');
            }
        });

        // menu_items — filtres fréquents
        Schema::table('menu_items', function (Blueprint $table) {
            if (! $this->indexExists('menu_items', 'menu_items_category_active_index')) {
                $table->index(['category_id', 'is_active', 'display_order'], 'menu_items_category_active_index');
            }
            if (! $this->indexExists('menu_items', 'menu_items_restaurant_available_index')) {
                $table->index(['restaurant_id', 'is_available'], 'menu_items_restaurant_available_index');
            }
        });

        // activity_logs — tri par date décroissant
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! $this->indexExists('activity_logs', 'activity_logs_restaurant_created_index')) {
                $table->index(['restaurant_id', 'created_at'], 'activity_logs_restaurant_created_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories',     fn (Blueprint $t) => $t->dropIndexIfExists('categories_restaurant_id_is_active_index'));
        Schema::table('menu_items',     fn (Blueprint $t) => $t->dropIndexIfExists('menu_items_category_active_index'));
        Schema::table('menu_items',     fn (Blueprint $t) => $t->dropIndexIfExists('menu_items_restaurant_available_index'));
        Schema::table('activity_logs',  fn (Blueprint $t) => $t->dropIndexIfExists('activity_logs_restaurant_created_index'));
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(\DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $index]))->isNotEmpty();
    }
};
