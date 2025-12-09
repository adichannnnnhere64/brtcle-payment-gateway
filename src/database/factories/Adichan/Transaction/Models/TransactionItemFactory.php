<?php

namespace Database\Factories\Adichan\Transaction\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Adichan\Transaction\Models\TransactionItem;

class TransactionItemFactory extends Factory
{
    protected $model = TransactionItem::class;

    public function definition(): array
    {
        return [
            'transaction_id' => TransactionFactory::new(),
            'itemable_id' => 1, // Assume a product ID; override in tests
            'itemable_type' => 'Adichan\Product\Models\Product', // Assume Product class; override as needed
            'quantity' => $this->faker->numberBetween(1, 10),
            'price_at_time' => $this->faker->randomFloat(2, 10, 100),
            'subtotal' => $this->faker->randomFloat(2, 10, 1000),
        ];
    }
}
