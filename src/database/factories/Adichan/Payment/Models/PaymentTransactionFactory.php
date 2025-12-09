<?php

namespace Database\Factories\Adichan\Payment\Models;

use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Transaction\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(), // ← Add this
            'gateway_id' => PaymentGateway::factory(),
            'gateway_transaction_id' => $this->faker->uuid(),
            'gateway_name' => $this->faker->randomElement(['stripe', 'paypal', 'internal']),
            'amount' => $this->faker->randomFloat(4, 1, 1000),
            'currency' => 'USD',
            'status' => $this->faker->randomElement(['pending', 'completed', 'failed', 'refunded']),
            'payment_method' => $this->faker->creditCardType(),
            'payer_info' => [
                'email' => $this->faker->email(),
                'name' => $this->faker->name(),
            ],
            'metadata' => [
                'description' => $this->faker->sentence(),
            ],
            'webhook_received' => $this->faker->boolean(),
            'verified_at' => $this->faker->optional()->dateTime(),
        ];
    }

    // Add helper methods for different states
    public function forTransaction(Transaction $transaction): self
    {
        return $this->state([
            'transaction_id' => $transaction->id,
        ]);
    }

    public function verified(): self
    {
        return $this->state([
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function pending(): self
    {
        return $this->state([
            'status' => 'pending',
            'verified_at' => null,
        ]);
    }
}
