<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_register_id');
            $table->string('status', 20)->default('open'); // open | closed
            $table->integer('opening_balance')->default(0);
            $table->uuid('opened_by');
            $table->timestamp('opened_at');
            $table->integer('expected_balance')->nullable();
            $table->integer('closing_balance')->nullable();
            $table->integer('difference')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->cascadeOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['cash_register_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
