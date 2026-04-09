<?php

// database/factories/TableFactory.php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TableFactory extends Factory
{
    protected $model = Table::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'number' => (string) fake()->numberBetween(1, 50),
            'capacity' => fake()->numberBetween(2, 8),
            'status' => 'free',
            'qr_code' => 'https://clicettable.com/t/'.Str::random(8),
        ];
    }
}
