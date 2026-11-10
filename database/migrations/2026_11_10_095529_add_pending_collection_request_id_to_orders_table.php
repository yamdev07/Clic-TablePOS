<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('pending_collection_request_id')->nullable()->after('table_id');
            // Un index unique partiel n'est pas supporté par MySQL : l'unicité est garantie applicativement
            // (transaction + lockForUpdate sur l'order avant insertion).
            $table->index('pending_collection_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['pending_collection_request_id']);
            $table->dropColumn('pending_collection_request_id');
        });
    }
};
