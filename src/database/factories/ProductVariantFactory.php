<?php

namespace Database\Factories;

use Adichan\Product\Models\Product;
use Adichan\Product\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariationFactory extends Factory
{
    protected $model = ProductVariation::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attributes' => json_encode(['size' => $this->faker->randomElement(['small', 'medium', 'large'])]),
            'price_override' => $this->faker->optional()->randomFloat(2, 1, 100),
            'sku_data' => json_encode(['sku' => $this->faker->uuid]),
        ];
    }
}
