<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // orders.user_id — FK utilisé dans with('user')
        Schema::table('orders', function (Blueprint $table) {
            $table->index('user_id');
            $table->index(['restaurant_id', 'created_at']);
        });

        // order_items.menu_item_id — FK utilisé dans with('menuItem')
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('menu_item_id');
        });

        // tables.current_order_id — FK utilisé dans with('currentOrder')
        Schema::table('tables', function (Blueprint $table) {
            $table->index('current_order_id');
            $table->index('number');
        });

        // payments.user_id
        Schema::table('payments', function (Blueprint $table) {
            $table->index('user_id');
        });

        // activity_logs.user_id + composite restaurant/date
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('user_id');
            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders',        fn($t) => $t->dropIndex(['user_id']));
        Schema::table('order_items',   fn($t) => $t->dropIndex(['menu_item_id']));
        Schema::table('tables',        fn($t) => $t->dropIndex(['current_order_id']));
        Schema::table('payments',      fn($t) => $t->dropIndex(['user_id']));
        Schema::table('activity_logs', fn($t) => $t->dropIndex(['user_id']));
    }
};
