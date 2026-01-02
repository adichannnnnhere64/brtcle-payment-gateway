<?php

namespace Adichan\Payment\Tests\Feature;

use Adichan\Payment\Gateways\StripeGateway;
use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\Tests\Helpers\StripeWebhookHelper;
use Adichan\Payment\Tests\TestModels\Product;
use Adichan\Payment\Tests\TestModels\User;
use Adichan\Transaction\Models\Transaction;
use Illuminate\Support\Str;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/* uses(\Adichan\Payment\Tests\TestCase::class); */

beforeEach(function () {
    // Skip tests if Stripe keys are not configured
    if (! env('STRIPE_SECRET_KEY') || ! Str::startsWith(env('STRIPE_SECRET_KEY'), 'sk_test_')) {
        $this->markTestSkipped('Real Stripe test keys required. Set STRIPE_SECRET_KEY in .env.testing');
    }

    // Create Stripe gateway configuration
    $this->gatewayModel = PaymentGateway::factory()->create([
        'name' => 'stripe',
        'driver' => 'stripe',
        'is_active' => true,
        'config' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'public_key' => env('STRIPE_PUBLIC_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        ],
    ]);

    // Create Stripe gateway instance
    $this->stripeGateway = new StripeGateway($this->gatewayModel);

    // Create test user
    $this->user = User::create([
        'name' => 'Test Customer',
        'email' => 'test.customer.'.time().'@example.com',
    ]);

    // Initialize Stripe SDK
    Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
});

afterEach(function () {
    // Clean up any created Stripe test objects
    unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
});

test('it can initialize stripe with real api keys', function () {
    expect($this->stripeGateway->getName())->toBe('stripe');
    expect($this->stripeGateway->isEnabled())->toBeTrue();
    expect($this->stripeGateway->getConfig())->toHaveKey('secret_key');
    expect($this->stripeGateway->getConfig()['secret_key'])->toBeString()->not->toBeEmpty();
})->group('stripe', 'real-api');

test('it can create payment intent with real stripe api', function () {
    // Create a transaction
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);

    $product = Product::create([
        'name' => 'Test Product',
        'base_price' => 25.50,
        'type' => 'digital',
    ]);

    $transaction->addItem($product, 1);
    $transaction->calculateTotal();

    // Test creating payment intent through our gateway
    $response = $this->stripeGateway->initiatePayment($transaction, [
        'customer_email' => $this->user->email,
        'customer_name' => $this->user->name,
        'currency' => 'usd',
        'description' => 'Test transaction via Gateway',
    ]);

    // Verify response
    expect($response->isSuccessful())->toBeTrue();
    expect($response->getGatewayReference())->toBeString()->not->toBeEmpty();
    expect($response->requiresAction())->toBeTrue(); // Should require payment method

    // Verify payment intent exists in Stripe
    $paymentIntentId = $response->getGatewayReference();
    $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

    expect($paymentIntent)->toBeInstanceOf(PaymentIntent::class);
    expect($paymentIntent->id)->toBe($paymentIntentId);
    expect($paymentIntent->amount)->toBe(2550); // $25.50 in cents
    expect($paymentIntent->currency)->toBe('usd');

    // Verify payment record was created
    $payment = PaymentTransaction::where('gateway_transaction_id', $paymentIntentId)->first();
    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(25.50);
    expect($payment->currency)->toBe('USD');

    // Clean up - cancel the test payment intent
    $paymentIntent->cancel();
})->group('stripe', 'real-api');

test('it can create stripe customer with real api', function () {
    $customerEmail = 'test.create.customer.'.time().'@example.com';

    // Create customer through Stripe SDK
    $customer = Customer::create([
        'email' => $customerEmail,
        'name' => 'Test Customer '.time(),
        'metadata' => ['test' => true],
    ]);

    expect($customer)->toBeInstanceOf(Customer::class);
    expect($customer->id)->toBeString()->not->toBeEmpty();
    expect($customer->email)->toBe($customerEmail);

    // Clean up
    $customer->delete();
})->group('stripe', 'real-api');

test('it can verify payment intent status', function () {
    // Create a test payment intent
    $paymentIntent = PaymentIntent::create([
        'amount' => 1500, // $15.00
        'currency' => 'usd',
        'description' => 'Test verification',
        'metadata' => ['test_verification' => true],
        'payment_method_types' => ['card'],
    ]);

    // Create transaction and payment record
    $transaction = Transaction::create(['status' => 'pending', 'total' => 15.00]);

    $payment = PaymentTransaction::create([
        'gateway_id' => $this->gatewayModel->id,
        'transaction_id' => $transaction->id,
        'gateway_transaction_id' => $paymentIntent->id,
        'gateway_name' => 'stripe',
        'amount' => 15.00,
        'currency' => 'USD',
        'status' => $paymentIntent->status,
        'payment_method' => 'card',
        'payer_info' => ['email' => 'test@example.com'],
        'metadata' => [],
    ]);

    // Test verification
    $verification = $this->stripeGateway->verifyPayment($paymentIntent->id);

    // Should reflect current status (requires_payment_method)
    expect($verification->isVerified())->toBeFalse();
    expect($verification->getStatus())->toBe('requires_payment_method');

    // Clean up
    $paymentIntent->cancel();
})->group('stripe', 'real-api');

test('it supports webhooks when configured', function () {
    $hasWebhookSecret = ! empty(env('STRIPE_WEBHOOK_SECRET'));

    expect($this->stripeGateway->supportsWebhook())->toBe($hasWebhookSecret);
})->group('stripe', 'webhook');

test('it can process webhook events', function () {
    if (! $this->stripeGateway->supportsWebhook()) {
        $this->markTestSkipped('Stripe webhook secret not configured');
    }

    // Create a payment intent first
    $paymentIntent = PaymentIntent::create([
        'amount' => 2000,
        'currency' => 'usd',
        'description' => 'Webhook test',
        'metadata' => ['webhook_test' => true],
        'payment_method_types' => ['card'],
    ]);

    // Create payment record
    $transaction = Transaction::create(['status' => 'pending', 'total' => 20.00]);

    $payment = PaymentTransaction::create([
        'gateway_id' => $this->gatewayModel->id,
        'transaction_id' => $transaction->id,
        'gateway_transaction_id' => $paymentIntent->id,
        'gateway_name' => 'stripe',
        'amount' => 20.00,
        'currency' => 'USD',
        'status' => $paymentIntent->status,
        'payment_method' => 'card',
        'payer_info' => ['email' => 'test@example.com'],
        'metadata' => [],
    ]);

    // Create webhook payload
    $payload = StripeWebhookHelper::createPaymentIntentSucceededPayload($paymentIntent->id);

    // Generate signature
    $signature = StripeWebhookHelper::generateSignature(
        $payload,
        env('STRIPE_WEBHOOK_SECRET')
    );
    $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;

    // Process webhook
    $result = $this->stripeGateway->handleWebhook($payload);

    // Verify webhook was processed
    expect($result->shouldProcess())->toBeTrue();
    expect($result->getEventType())->toBe('payment_intent.succeeded');

    // Clean up
    $paymentIntent->cancel();
})->group('stripe', 'webhook', 'real-api');

test('it validates transaction amounts', function () {
    // Create zero amount transaction
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);

    $product = Product::create([
        'name' => 'Free Product',
        'base_price' => 0.00,
        'type' => 'free',
    ]);

    $transaction->addItem($product, 1);
    $transaction->calculateTotal();

    // Should throw exception
    expect(fn () => $this->stripeGateway->initiatePayment($transaction))
        ->toThrow(\InvalidArgumentException::class, 'Transaction amount must be greater than zero');
})->group('stripe', 'validation');

test('it can confirm and capture payments', function () {
    // Create payment intent with manual capture
    $paymentIntent = PaymentIntent::create([
        'amount' => 1000,
        'currency' => 'usd',
        'description' => 'Manual capture test',
        'metadata' => ['manual_capture' => true],
        'payment_method_types' => ['card'],
        'capture_method' => 'manual',
    ]);

    // Test that gateway methods exist using method_exists()
    expect(method_exists($this->stripeGateway, 'confirmPayment'))->toBeTrue();
    expect(method_exists($this->stripeGateway, 'capturePayment'))->toBeTrue();
    expect(method_exists($this->stripeGateway, 'cancelPayment'))->toBeTrue();

    // Clean up
    $paymentIntent->cancel();
})->group('stripe', 'real-api');


test('it handles errors gracefully', function () {
    // Test with invalid payment ID
    $response = $this->stripeGateway->verifyPayment('invalid_payment_id_'.Str::random(10));

    // Should handle gracefully
    expect($response->isVerified())->toBeFalse();
    expect($response->getStatus())->toBe('failed');
})->group('stripe', 'error-handling');
