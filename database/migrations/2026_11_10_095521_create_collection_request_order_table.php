<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_request_order', function (Blueprint $table) {
            $table->uuid('collection_request_id');
            $table->uuid('order_id');

            $table->primary(['collection_request_id', 'order_id']);
            $table->index('order_id');

            $table->foreign('collection_request_id')
                ->references('id')->on('collection_requests')->cascadeOnDelete();
            $table->foreign('order_id')
                ->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_request_order');
    }
};
