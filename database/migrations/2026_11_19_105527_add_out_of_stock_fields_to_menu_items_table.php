<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->timestamp('out_of_stock_at')->nullable()->after('is_available');
            $table->string('out_of_stock_reason', 255)->nullable()->after('out_of_stock_at');
            $table->uuid('out_of_stock_by')->nullable()->after('out_of_stock_reason');
            $table->foreign('out_of_stock_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['out_of_stock_by']);
            $table->dropColumn(['out_of_stock_at', 'out_of_stock_reason', 'out_of_stock_by']);
        });
    }
};
