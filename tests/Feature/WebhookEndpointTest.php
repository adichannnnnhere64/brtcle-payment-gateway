<?php

namespace Adichan\Payment\Tests\Feature;

use Adichan\Payment\Interfaces\PaymentServiceInterface;
use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\Tests\Helpers\StripeWebhookHelper;
use Adichan\Transaction\Models\Transaction;
use Illuminate\Support\Str;

/* uses(\Adichan\Payment\Tests\TestCase::class); */

beforeEach(function () {
    if (! env('STRIPE_WEBHOOK_SECRET')) {
        $this->markTestSkipped('Stripe webhook secret required');
    }

    $this->paymentService = app(PaymentServiceInterface::class);

    // Set up gateway
    PaymentGateway::updateOrCreate(
        ['driver' => 'stripe'],
        [
            'name' => 'stripe',
            'driver' => 'stripe',
            'is_active' => true,
            'config' => [
                'secret_key' => env('STRIPE_SECRET_KEY', ''),
                'public_key' => env('STRIPE_PUBLIC_KEY', ''),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            ],
        ]
    );
});

test('webhook endpoint processes payment_intent.succeeded', function () {
    $paymentIntentId = 'pi_test_'.Str::random(24);

    // Create payment record
    $transaction = Transaction::create(['status' => 'pending', 'total' => 20.00]);

    $payment = PaymentTransaction::create([
        'gateway_id' => PaymentGateway::where('driver', 'stripe')->first()->id,
        'transaction_id' => $transaction->id,
        'gateway_transaction_id' => $paymentIntentId,
        'gateway_name' => 'stripe',
        'amount' => 20.00,
        'currency' => 'USD',
        'status' => 'requires_payment_method',
        'payment_method' => 'card',
        'payer_info' => ['email' => 'test@example.com'],
        'metadata' => [],
    ]);

    // Create webhook payload
    $payload = StripeWebhookHelper::createPaymentIntentSucceededPayload($paymentIntentId);

    // Generate signature
    $signature = StripeWebhookHelper::generateSignature(
        $payload,
        env('STRIPE_WEBHOOK_SECRET')
    );

    // Set signature header
    $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;

    // Process webhook
    $gateway = $this->paymentService->gateway('stripe');
    $result = $gateway->handleWebhook($payload);

    // Verify results
    expect($result->shouldProcess())->toBeTrue();
    expect($result->getEventType())->toBe('payment_intent.succeeded');
    expect($result->getGatewayReference())->toBe($paymentIntentId);

    // Payment should be marked as succeeded
    $payment->refresh();
    expect($payment->status)->toBe('verified');
})->group('stripe', 'webhook');

test('webhook endpoint handles invalid signature', function () {
    $payload = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'test']]];

    // Set invalid signature
    $_SERVER['HTTP_STRIPE_SIGNATURE'] = 'invalid_signature';

    $gateway = $this->paymentService->gateway('stripe');
    $result = $gateway->handleWebhook($payload);

    // Should indicate signature failure
    expect($result->getEventType())->toBe('signature_verification_failed');
    expect($result->shouldProcess())->toBeFalse();
})->group('stripe', 'webhook');

test('webhook endpoint handles unknown payment', function () {
    $payload = StripeWebhookHelper::createPaymentIntentSucceededPayload('unknown_payment_id');

    $signature = StripeWebhookHelper::generateSignature(
        $payload,
        env('STRIPE_WEBHOOK_SECRET')
    );
    $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;

    $gateway = $this->paymentService->gateway('stripe');
    $result = $gateway->handleWebhook($payload);

    // Should process but indicate payment not found
    expect($result->shouldProcess())->toBeFalse();
})->group('stripe', 'webhook');
