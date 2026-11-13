<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('cash_session_id')->nullable()->after('payment_group_id');
            $table->foreign('cash_session_id')
                ->references('id')->on('cash_sessions')->nullOnDelete();
            $table->index('cash_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropIndex(['cash_session_id']);
            $table->dropColumn('cash_session_id');
        });
    }
};
