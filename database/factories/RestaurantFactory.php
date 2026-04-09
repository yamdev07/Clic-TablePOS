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
        ];
    }
}
