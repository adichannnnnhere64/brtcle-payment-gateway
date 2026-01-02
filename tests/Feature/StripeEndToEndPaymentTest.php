<?php

namespace Adichan\Payment\Tests\Feature;

use Adichan\Payment\Interfaces\PaymentServiceInterface;
use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\Tests\TestModels\Product;
use Adichan\Payment\Tests\TestModels\User;
use Adichan\Transaction\Models\Transaction;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/* uses(\Adichan\Payment\Tests\TestCase::class); */

beforeEach(function () {
    if (! env('STRIPE_SECRET_KEY')) {
        $this->markTestSkipped('Stripe keys required for end-to-end tests');
    }

    // Set up payment service
    $this->paymentService = app(PaymentServiceInterface::class);
    $this->paymentService->setGateway('stripe');

    // Create or update Stripe gateway
    PaymentGateway::updateOrCreate(
        ['driver' => 'stripe'],
        [
            'name' => 'stripe',
            'driver' => 'stripe',
            'is_active' => true,
            'config' => [
                'secret_key' => env('STRIPE_SECRET_KEY'),
                'public_key' => env('STRIPE_PUBLIC_KEY'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
            ],
        ]
    );

    // Create test user
    $this->customer = User::create([
        'name' => 'End-to-End Customer',
        'email' => 'e2e.customer.'.time().'@example.com',
    ]);

    // Initialize Stripe
    Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
});

test('complete payment flow with stripe works', function () {
    // 1. Create product
    $product = Product::create([
        'name' => 'Premium Service',
        'base_price' => 49.99,
        'type' => 'service',
    ]);

    // 2. Create transaction
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();

    expect($transaction->getTotal())->toBe(49.99);

    // 3. Initiate payment
    $response = $this->paymentService->pay($transaction, [
        'customer_email' => $this->customer->email,
        'customer_name' => $this->customer->name,
        'description' => 'Premium Service Payment',
        'currency' => 'usd',
        'metadata' => [
            'customer_id' => $this->customer->id,
            'transaction_type' => 'service',
        ],
    ]);

    // 4. Verify payment initiation
    expect($response->isSuccessful())->toBeTrue();
    expect($response->getGatewayReference())->toBeString()->not->toBeEmpty();
    expect($response->requiresAction())->toBeTrue();

    $paymentIntentId = $response->getGatewayReference();

    // 5. Verify payment record
    $paymentRecord = PaymentTransaction::where('gateway_transaction_id', $paymentIntentId)->first();
    expect($paymentRecord)->not->toBeNull();
    expect($paymentRecord->amount)->toBe(49.99);
    expect($paymentRecord->status)->toBe('requires_payment_method');

    // 6. Verify in Stripe
    $stripePaymentIntent = PaymentIntent::retrieve($paymentIntentId);
    expect($stripePaymentIntent->amount)->toBe(4999); // $49.99 in cents
    expect($stripePaymentIntent->description)->toBe('Premium Service Payment');

    // 7. Test verification before completion
    $verification = $this->paymentService->verify($paymentIntentId, ['gateway' => 'stripe']);
    expect($verification->isVerified())->toBeFalse();
    expect($verification->getStatus())->toBe('requires_payment_method');

    // 8. Clean up
    $stripePaymentIntent->cancel();

    // 9. Transaction should still be pending
    $transaction->refresh();
    expect($transaction->getStatus())->toBe('pending');
})->group('stripe', 'e2e', 'real-api');

test('payment service integrates with internal wallet gateway', function () {
    // Enable internal gateway
    PaymentGateway::updateOrCreate(
        ['driver' => 'internal'],
        [
            'name' => 'internal',
            'driver' => 'internal',
            'is_active' => true,
            'config' => [],
        ]
    );

    // Test internal gateway exists
    $internalGateway = $this->paymentService->gateway('internal');
    expect($internalGateway->getName())->toBe('Wallet');
    expect($internalGateway->isEnabled())->toBeTrue();
})->group('internal', 'integration');

test('it supports multiple payment methods', function () {
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $product = Product::create(['name' => 'Multi-method Test', 'base_price' => 30.00, 'type' => 'digital']);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();

    $gateway = $this->paymentService->gateway('stripe');
    
    $response = $gateway->initiatePayment($transaction, [
        'customer_email' => $this->customer->email,
        'payment_method_types' => ['card', 'link'], 
        'currency' => 'usd', 
        'description' => 'Multiple payment method test',
    ]);

    expect($response->isSuccessful())->toBeTrue();

    // Clean up
    if ($response->getGatewayReference()) {
        try {
            $paymentIntent = PaymentIntent::retrieve($response->getGatewayReference());
            $paymentIntent->cancel();
        } catch (\Exception $e) {
            // Ignore
        }
    }
})->group('stripe', 'real-api');

test('it handles payment status changes', function () {
    // Create payment intent
    $paymentIntent = PaymentIntent::create([
        'amount' => 1000,
        'currency' => 'usd',
        'description' => 'Status tracking test',
        'metadata' => ['test' => true],
        'payment_method_types' => ['card'],
    ]);

    $transaction = Transaction::create(['status' => 'pending', 'total' => 10.00]);

    $payment = PaymentTransaction::create([
        'gateway_id' => PaymentGateway::where('driver', 'stripe')->first()->id,
        'transaction_id' => $transaction->id,
        'gateway_transaction_id' => $paymentIntent->id,
        'gateway_name' => 'stripe',
        'amount' => 10.00,
        'currency' => 'USD',
        'status' => $paymentIntent->status,
        'payment_method' => 'card',
        'payer_info' => ['email' => $this->customer->email],
        'metadata' => ['initial_status' => $paymentIntent->status],
    ]);

    // Verify initial status
    expect($payment->status)->toBe('requires_payment_method');

    // Simulate status update
    $payment->update(['status' => 'processing']);
    expect($payment->fresh()->status)->toBe('processing');

    // Clean up
    $paymentIntent->cancel();
})->group('stripe', 'real-api');
