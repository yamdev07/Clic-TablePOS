<?php

// database/factories/RestaurantFactory.php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => fake()->company().' Restaurant',
            'slug' => fake()->slug(),
            'email' => fake()->unique()->companyEmail(),
            'status' => 'active',
            'settings' => json_encode([
                'auto_assign_orders' => true,
                'kitchen_print_auto' => true,
                'enable_prepayment' => false,
                'tax_enabled' => true,
                'service_charge_enabled' => true,
            ]),
        ];
    }
}
