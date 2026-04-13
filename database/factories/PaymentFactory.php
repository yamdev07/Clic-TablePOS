<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'id'       => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'user_id'  => User::factory(),
            'amount'   => fake()->numberBetween(1000, 50000),
            'method'   => fake()->randomElement(['cash', 'card', 'wave', 'orange_money']),
            'status'   => 'completed',
        ];
    }

    public function cash(): static
    {
        return $this->state(['method' => 'cash']);
    }

    public function wave(): static
    {
        return $this->state(['method' => 'wave']);
    }

    public function forAmount(int $amount): static
    {
        return $this->state(['amount' => $amount]);
    }
}
