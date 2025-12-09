<?php

namespace Database\Factories\Adichan\Transaction\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Adichan\Transaction\Models\Transaction;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
            'total' => 0.0,
        ];
    }
}
