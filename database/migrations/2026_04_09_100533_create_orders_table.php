<?php

// database/migrations/2024_01_01_000006_create_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restaurant_id');
            $table->uuid('table_id');
            $table->uuid('user_id');
            $table->string('order_number')->unique();
            $table->enum('status', ['open', 'in_progress', 'ready', 'served', 'paid', 'cancelled'])->default('open');
            $table->enum('type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
            $table->integer('subtotal')->default(0);
            $table->integer('tax')->default(0);
            $table->integer('service_charge')->default(0);
            $table->integer('discount')->default(0);
            $table->integer('total')->default(0);
            $table->integer('paid_amount')->default(0);
            $table->integer('due_amount')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('[]');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->onDelete('cascade');

            $table->foreign('table_id')
                ->references('id')
                ->on('tables');

            $table->foreign('user_id')
                ->references('id')
                ->on('users');

            $table->index(['restaurant_id', 'status']);
            $table->index(['table_id', 'status']);
            $table->index('order_number');
            $table->index('created_at');
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
