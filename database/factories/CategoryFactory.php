<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->word(),
            'slug' => fake()->unique()->slug(2),
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
