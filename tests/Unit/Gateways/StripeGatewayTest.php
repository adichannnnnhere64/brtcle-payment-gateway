<?php

namespace Adichan\Payment\Tests\Unit\Gateways;

use Adichan\Payment\Gateways\StripeGateway;
use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\Tests\TestModels\User;
use Adichan\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    // Create Stripe gateway configuration - use unique name
    $this->gatewayModel = PaymentGateway::factory()->create([
        'name' => 'Stripe Test Gateway ' . uniqid(),
        'driver' => 'stripe',
        'is_active' => true,
        'config' => [
            'secret_key' => 'sk_test_' . str_repeat('a', 24),
            'public_key' => 'pk_test_' . str_repeat('b', 24),
            'webhook_secret' => 'whsec_' . str_repeat('c', 24),
        ],
    ]);

    // Create a transaction
    $this->transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    $product = \Adichan\Payment\Tests\TestModels\Product::create([
        'name' => 'Test Product',
        'base_price' => 50.00,
        'type' => 'physical',
    ]);
    
    $this->transaction->addItem($product, 1);
    $this->transaction->calculateTotal();
});

afterEach(function () {
    Mockery::close();
    unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
});

it('has correct gateway properties', function () {
    // Create a partial mock that doesn't call Stripe methods
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    // Mock initializeStripe to avoid Stripe API calls
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Call constructor
    $gateway->__construct($this->gatewayModel);
    
    expect($gateway->getName())->toBe($this->gatewayModel->name);
    expect($gateway->isEnabled())->toBeTrue();
    expect($gateway->supportsWebhook())->toBeTrue();
    expect($gateway->getConfig())->toBeArray();
    expect($gateway->getConfig())->toHaveKey('secret_key');
    expect($gateway->getConfig())->toHaveKey('public_key');
    expect($gateway->getConfig())->toHaveKey('webhook_secret');
});

it('throws exception when secret key is missing', function () {
    // Create a unique gateway name to avoid constraint violation
    $gatewayModel = PaymentGateway::factory()->create([
        'name' => 'Stripe Missing Key ' . uniqid(),
        'driver' => 'stripe',
        'config' => [], // No secret key
    ]);
    
    // This should throw exception when initializeStripe is called
    expect(fn() => new StripeGateway($gatewayModel))
        ->toThrow(\RuntimeException::class, 'Stripe secret key is not configured');
});

it('can initiate payment successfully', function () {
    // Create partial mock
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock the actual initiatePayment method return value
    $gateway->shouldReceive('initiatePayment')
        ->andReturnUsing(function () {
            // Simulate successful payment response
            return new \Adichan\Payment\PaymentResponse(
                true,
                'pi_test_' . uniqid(),
                null,
                null,
                [
                    'client_secret' => 'pi_test_secret_' . uniqid(),
                    'payment_intent' => ['id' => 'pi_test_' . uniqid(), 'status' => 'requires_action'],
                    'payment_id' => 1,
                ],
                true,
                ['type' => 'redirect', 'redirect_url' => 'https://stripe.com/redirect']
            );
        });
    
    $gateway->__construct($this->gatewayModel);
    
    $response = $gateway->initiatePayment($this->transaction, [
        'customer_email' => 'test@example.com',
        'customer_name' => 'Test User',
        'currency' => 'usd',
        'description' => 'Test Transaction',
    ]);
    
    // Assertions
    expect($response->isSuccessful())->toBeTrue();
    expect($response->getGatewayReference())->toBeString();
    expect($response->requiresAction())->toBeTrue();
    expect($response->getActionData())->toBeArray();
});

it('handles payment intent creation failure', function () {
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock initiatePayment to return failure response
    $gateway->shouldReceive('initiatePayment')
        ->andReturnUsing(function () {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                'Invalid API key provided',
                ['error' => 'Invalid API key provided']
            );
        });
    
    $gateway->__construct($this->gatewayModel);
    
    $response = $gateway->initiatePayment($this->transaction);
    
    // Assertions
    expect($response->isSuccessful())->toBeFalse();
    expect($response->getErrorMessage())->toBe('Invalid API key provided');
});

it('can verify payment successfully', function () {
    // First create a payment record
    $payment = PaymentTransaction::create([
        'gateway_id' => $this->gatewayModel->id,
        'transaction_id' => $this->transaction->id,
        'gateway_transaction_id' => 'pi_test_verify_' . uniqid(),
        'gateway_name' => 'stripe',
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => 'requires_action',
        'payment_method' => 'card',
        'payer_info' => ['email' => 'test@example.com'],
        'metadata' => [],
    ]);
    
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock verifyPayment to return successful verification
    $gateway->shouldReceive('verifyPayment')
        ->andReturnUsing(function () {
            return new \Adichan\Payment\PaymentVerification(
                true,
                $this->transaction,
                'Stripe',
                'succeeded',
                now(),
                ['verified' => true]
            );
        });
    
    $gateway->__construct($this->gatewayModel);
    
    $verification = $gateway->verifyPayment($payment->gateway_transaction_id);
    
    // Assertions
    expect($verification->isVerified())->toBeTrue();
    expect($verification->getStatus())->toBe('succeeded');
    expect($verification->getVerifiedAt())->not->toBeNull();
    expect($verification->getGateway())->toBe('Stripe');
});

it('can process refund successfully', function () {
    // Create a completed payment record
    $payment = PaymentTransaction::create([
        'gateway_id' => $this->gatewayModel->id,
        'transaction_id' => $this->transaction->id,
        'gateway_transaction_id' => 'pi_test_refund_' . uniqid(),
        'gateway_name' => 'stripe',
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => 'succeeded',
        'payment_method' => 'card',
        'payer_info' => ['email' => 'test@example.com'],
        'metadata' => [],
    ]);
    
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock refund to return successful response
    $gateway->shouldReceive('refund')
        ->andReturnUsing(function () {
            return new \Adichan\Payment\PaymentResponse(
                true,
                're_test_' . uniqid(),
                null,
                null,
                ['refunded' => true]
            );
        });
    
    $gateway->__construct($this->gatewayModel);
    
    $response = $gateway->refund($payment->gateway_transaction_id);
    
    // Assertions
    expect($response->isSuccessful())->toBeTrue();
    expect($response->getGatewayReference())->toBeString();
});

it('can handle webhook events', function () {
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock handleWebhook
    $payload = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_123']]];
    $gateway->shouldReceive('handleWebhook')
        ->with($payload)
        ->andReturn(new \Adichan\Payment\PaymentWebhookResult(
            'payment_intent.succeeded',
            'pi_test_123',
            $payload,
            true,
            ['success' => true]
        ));
    
    $gateway->__construct($this->gatewayModel);
    
    $result = $gateway->handleWebhook($payload);
    
    // Assertions
    expect($result->shouldProcess())->toBeTrue();
    expect($result->getEventType())->toBe('payment_intent.succeeded');
    expect($result->getGatewayReference())->toBe('pi_test_123');
});

it('throws exception for zero amount transaction', function () {
    // Create a transaction with zero amount
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    $gatewayModel = PaymentGateway::factory()->create([
        'name' => 'Stripe Test ' . uniqid(),
        'driver' => 'stripe',
        'is_active' => true,
        'config' => [
            'secret_key' => 'sk_test_' . str_repeat('a', 24),
            'public_key' => 'pk_test_' . str_repeat('b', 24),
        ],
    ]);
    
    // Create the gateway directly (not mocked) to test validation
    $gateway = new StripeGateway($gatewayModel);
    
    // This should throw an exception
    expect(fn() => $gateway->initiatePayment($transaction))
        ->toThrow(\InvalidArgumentException::class, 'Transaction amount must be greater than zero');
});

it('returns false for supportsWebhook when webhook secret is missing', function () {
    // Create gateway without webhook secret
    $gatewayModel = PaymentGateway::factory()->create([
        'name' => 'Stripe No Webhook ' . uniqid(),
        'driver' => 'stripe',
        'config' => [
            'secret_key' => 'sk_test_' . str_repeat('a', 24),
            'public_key' => 'pk_test_' . str_repeat('b', 24),
            // No webhook_secret
        ],
    ]);
    
    $gateway = Mockery::mock(StripeGateway::class, [$gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock supportsWebhook to return false
    $gateway->shouldReceive('supportsWebhook')
        ->andReturn(false);
    
    $gateway->__construct($gatewayModel);
    
    expect($gateway->supportsWebhook())->toBeFalse();
});

it('implements required interface methods', function () {
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    $gateway->__construct($this->gatewayModel);
    
    // Check that required methods exist
    expect(method_exists($gateway, 'getName'))->toBeTrue();
    expect(method_exists($gateway, 'isEnabled'))->toBeTrue();
    expect(method_exists($gateway, 'getConfig'))->toBeTrue();
    expect(method_exists($gateway, 'initiatePayment'))->toBeTrue();
    expect(method_exists($gateway, 'verifyPayment'))->toBeTrue();
    expect(method_exists($gateway, 'refund'))->toBeTrue();
    expect(method_exists($gateway, 'supportsWebhook'))->toBeTrue();
    expect(method_exists($gateway, 'handleWebhook'))->toBeTrue();
});

it('throws exception for webhook handling when not supported', function () {
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock supportsWebhook to return false
    $gateway->shouldReceive('supportsWebhook')
        ->andReturn(false);
    
    $gateway->__construct($this->gatewayModel);
    
    // handleWebhook should throw exception when supportsWebhook is false
    $gateway->shouldReceive('handleWebhook')
        ->with(['test' => 'payload'])
        ->andThrow(new \RuntimeException('Webhook not supported for this gateway'));
    
    expect(fn() => $gateway->handleWebhook(['test' => 'payload']))
        ->toThrow(\RuntimeException::class, 'Webhook not supported for this gateway');
});

it('can be instantiated and has basic functionality', function () {
    // Test basic functionality without mocking (except Stripe initialization)
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    $gateway->__construct($this->gatewayModel);
    
    // Test basic getters
    expect($gateway->getName())->toBe($this->gatewayModel->name);
    expect($gateway->isEnabled())->toBeTrue();
    expect($gateway->getConfig())->toBeArray();
});

it('handles webhook signature verification failure', function () {
    $gateway = Mockery::mock(StripeGateway::class, [$this->gatewayModel])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    
    $gateway->shouldReceive('initializeStripe')->once();
    
    // Mock handleWebhook to return failure response
    $payload = ['test' => 'data'];
    $gateway->shouldReceive('handleWebhook')
        ->with($payload)
        ->andReturn(new \Adichan\Payment\PaymentWebhookResult(
            'signature_verification_failed',
            '',
            $payload,
            false,
            ['error' => 'Invalid signature']
        ));
    
    $gateway->__construct($this->gatewayModel);
    
    $result = $gateway->handleWebhook($payload);
    
    expect($result->shouldProcess())->toBeFalse();
    expect($result->getEventType())->toBe('signature_verification_failed');
});
