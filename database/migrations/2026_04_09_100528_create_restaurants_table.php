<?php

// database/migrations/2024_01_01_000001_create_restaurants_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('logo')->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->string('timezone')->default('Africa/Dakar');
            $table->jsonb('settings')->default(json_encode([
                'auto_assign_orders' => true,
                'kitchen_print_auto' => true,
                'enable_prepayment' => false,
                'service_charge' => 0,
                'tax_rate' => 18,
            ]));
            $table->jsonb('payment_gateways')->default(json_encode([
                'cash' => true,
                'wave' => false,
                'orange_money' => false,
                'free_money' => false,
            ]));
            $table->enum('status', ['active', 'suspended', 'trial'])->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['slug', 'status']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
