<?php
// database/migrations/2024_01_01_000008_create_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('user_id');
            $table->integer('amount');
            $table->enum('method', ['cash', 'card', 'wave', 'orange_money', 'free_money']);
            $table->string('reference')->nullable();
            $table->jsonb('transaction_data')->default('[]');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('completed');
            $table->timestamps();
            
            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');
                  
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users');
                  
            $table->index(['order_id', 'status']);
            $table->index('reference');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};