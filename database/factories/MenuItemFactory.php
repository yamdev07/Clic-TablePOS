<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->word(),
            'price' => fake()->numberBetween(1000, 10000),
            'is_available' => true,
            'is_active' => true,
        ];
    }
}