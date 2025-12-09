<?php

namespace Database\Factories\Adichan\Payment\Models;

use Adichan\Payment\Models\PaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentGatewayFactory extends Factory
{
    protected $model = PaymentGateway::class;

    public function definition(): array
    {
        $gateways = ['stripe', 'paypal', 'internal'];
        $gateway = $this->faker->randomElement($gateways);
        
        $config = match($gateway) {
            'stripe' => [
                'secret_key' => 'sk_test_' . $this->faker->sha256(),
                'public_key' => 'pk_test_' . $this->faker->sha256(),
            ],
            'paypal' => [
                'client_id' => $this->faker->uuid(),
                'client_secret' => $this->faker->sha256(),
            ],
            default => [],
        };
        
        return [
            'name' => ucfirst($gateway),
            'driver' => $gateway,
            'config' => $config,
            'is_active' => $this->faker->boolean(80),
            'is_external' => $gateway !== 'internal',
            'priority' => $this->faker->numberBetween(1, 10),
            'meta' => [
                'description' => $this->faker->sentence(),
            ],
        ];
    }
    
    public function internal(): self
    {
        return $this->state([
            'name' => 'Wallet',
            'driver' => 'internal',
            'config' => [],
            'is_active' => true,
            'is_external' => false,
            'priority' => 1,
            'meta' => ['description' => 'Internal wallet payments'],
        ]);
    }
    
    public function stripe(): self
    {
        return $this->state([
            'name' => 'Stripe',
            'driver' => 'stripe',
            'config' => [
                'secret_key' => 'sk_test_' . $this->faker->sha256(),
                'public_key' => 'pk_test_' . $this->faker->sha256(),
            ],
            'is_active' => true,
            'is_external' => true,
            'priority' => 2,
        ]);
    }
    
    public function paypal(): self
    {
        return $this->state([
            'name' => 'PayPal',
            'driver' => 'paypal',
            'config' => [
                'client_id' => $this->faker->uuid(),
                'client_secret' => $this->faker->sha256(),
            ],
            'is_active' => true,
            'is_external' => true,
            'priority' => 3,
        ]);
    }
}
