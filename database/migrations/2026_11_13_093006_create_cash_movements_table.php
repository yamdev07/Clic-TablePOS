<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_session_id');
            $table->string('type', 10); // 'in' | 'out'
            $table->integer('amount');
            $table->string('reason', 255);
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['cash_session_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
