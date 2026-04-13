<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'id'            => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'table_id'      => Table::factory(),
            'user_id'       => User::factory(),
            'status'        => 'open',
            'subtotal'      => 0,
            'tax'           => 0,
            'service_charge'=> 0,
            'total'         => 0,
            'paid_amount'   => 0,
            'due_amount'    => 0,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'open']);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function withTotal(int $total): static
    {
        $subtotal = (int) round($total / 1.23);
        $tax      = (int) round($subtotal * 0.18);
        $service  = (int) round($subtotal * 0.05);

        return $this->state([
            'subtotal'       => $subtotal,
            'tax'            => $tax,
            'service_charge' => $service,
            'total'          => $total,
            'due_amount'     => $total,
        ]);
    }
}
