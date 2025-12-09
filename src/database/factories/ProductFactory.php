<?php

namespace Database\Factories;

use Adichan\Product\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'base_price' => $this->faker->randomFloat(2, 1, 100),
            'type' => $this->faker->randomElement(['coupon', 'vegetable', 'generic']),
            'meta' => json_encode(['description' => $this->faker->sentence]),
        ];
    }
}
