<?php

namespace Adichan\Payment\Tests\Unit\Gateways;

use Adichan\Payment\Gateways\InternalGateway;
use Adichan\Payment\Interfaces\PaymentServiceInterface;
use Adichan\Payment\Models\PaymentGateway;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\Tests\TestCase;
use Adichan\Payment\Tests\TestModels\User;
use Adichan\Transaction\Models\Transaction;
use Adichan\Transaction\Models\TransactionItem;
use Adichan\Wallet\Models\Wallet;
use Adichan\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

it('can be instantiated', function () {
    $gatewayModel = PaymentGateway::factory()->internal()->create([
        'is_active' => true,
    ]);
    
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    expect($gateway)->toBeInstanceOf(InternalGateway::class);
    expect($gateway->getName())->toBe($gatewayModel->name);
    expect($gateway->isEnabled())->toBeTrue();
    expect($gateway->supportsWebhook())->toBeFalse();
});

it('can initiate payment with sufficient balance', function () {
    // Create user with wallet
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    
    // Add funds to wallet
    $walletService = app(WalletService::class);
    $walletService->addFunds($user, 100.00, 'Initial deposit');
    
    // Create transaction
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    // Create product and add to transaction
    $product = \Adichan\Payment\Tests\TestModels\Product::create([
        'name' => 'Test Product',
        'base_price' => 50.00,
		'type' => 't'
    ]);
    
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    // Create gateway
    $gatewayModel = PaymentGateway::factory()->internal()->create([
        'is_active' => true,
    ]);
    
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    // Initiate payment
    $response = $gateway->initiatePayment($transaction, [
        'payer' => $user,
        'description' => 'Test payment',
    ]);
    
    // Assertions
    expect($response->isSuccessful())->toBeTrue();
    expect($response->getGatewayReference())->toBeString();
    expect($response->getErrorMessage())->toBeNull();
    
    // Verify transaction is completed
    $transaction->refresh();
    expect($transaction->getStatus())->toBe('completed');
    expect($transaction->getTotal())->toBe(50.00);
    
    // Verify wallet balance is deducted
    $balance = $walletService->getBalance($user);
    expect($balance)->toBe(50.00); // 100 - 50
    
    // Verify payment record exists
    $payment = PaymentTransaction::where('gateway_transaction_id', $response->getGatewayReference())->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('verified');
    expect($payment->amount)->toBe(50.00);
    expect($payment->transaction_id)->toBe($transaction->id);
});

it('fails payment with insufficient balance', function () {
    // Create user with empty wallet
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    
    // Create transaction with amount larger than balance
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    $product = \Adichan\Payment\Tests\TestModels\Product::create([
        'name' => 'Test Product',
        'base_price' => 100.00,
	'type' => 'type'
    ]);
    
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    // Create gateway
    $gatewayModel = PaymentGateway::factory()->internal()->create([
        'is_active' => true,
    ]);
    
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    // Initiate payment (should fail)
    $response = $gateway->initiatePayment($transaction, [
        'payer' => $user,
    ]);
    
    // Assertions
    expect($response->isSuccessful())->toBeFalse();
    expect($response->getErrorMessage())->toBe('Insufficient wallet balance');
    
    // Transaction should still be pending
    $transaction->refresh();
    expect($transaction->getStatus())->toBe('pending');
    
    // No payment record should be created
    $paymentCount = PaymentTransaction::count();
    expect($paymentCount)->toBe(0);
});

it('throws exception when payer not provided', function () {
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $product = \Adichan\Payment\Tests\TestModels\Product::create(['name' => 'Test', 'base_price' => 10.00, 'type' => 'type']);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    // Should throw exception when payer is not provided
    $gateway->initiatePayment($transaction, []);
})->throws(\InvalidArgumentException::class, 'Payer model is required for internal payments');

it('can verify payment', function () {
    // Create a completed payment first
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    $walletService = app(WalletService::class);
    $walletService->addFunds($user, 100.00);
    
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $product = \Adichan\Payment\Tests\TestModels\Product::create(['name' => 'Test', 'base_price' => 30.00, 'type' => 'coupon']);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $response = $gateway->initiatePayment($transaction, ['payer' => $user]);
    
    // Now verify the payment
    $verification = $gateway->verifyPayment($response->getGatewayReference());
    
    // Assertions
    expect($verification->isVerified())->toBeTrue();
    expect($verification->getTransaction()->id)->toBe($transaction->id);
    expect($verification->getGateway())->toBe($gatewayModel->name);
    expect($verification->getStatus())->toBe('verified');
    expect($verification->getVerifiedAt())->not->toBeNull();
});

it('can refund payment', function () {
    // Create and complete a payment
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    $walletService = app(WalletService::class);
    $walletService->addFunds($user, 100.00);
    
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $product = \Adichan\Payment\Tests\TestModels\Product::create(['name' => 'Test', 'base_price' => 40.00, 'type' => 'coupon']);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $response = $gateway->initiatePayment($transaction, ['payer' => $user]);
    
    // Get the payment ID
    $paymentId = $response->getGatewayReference();
    
    // Refund the payment
    $refundResponse = $gateway->refund($paymentId);
    
    // Assertions
    expect($refundResponse->isSuccessful())->toBeTrue();
    
    // Verify wallet balance is restored
    $balance = $walletService->getBalance($user);
    expect($balance)->toBe(100.00); // 100 - 40 + 40 refund
    
    // Verify payment status is updated
    $payment = PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
    expect($payment)->not->toBeNull();
});

it('can handle partial refund', function () {
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    $walletService = app(WalletService::class);
    $walletService->addFunds($user, 100.00);
    
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    $product = \Adichan\Payment\Tests\TestModels\Product::create(['name' => 'Test', 'base_price' => 50.00, 'type' => 'goods']);
    $transaction->addItem($product, 1);
    $transaction->calculateTotal();
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $response = $gateway->initiatePayment($transaction, ['payer' => $user]);
    $paymentId = $response->getGatewayReference();
    
    // Partial refund of 20.00
    $refundResponse = $gateway->refund($paymentId, 20.00);
    
    expect($refundResponse->isSuccessful())->toBeTrue();
    
    // Wallet should have 70.00 (100 - 50 + 20)
    $balance = $walletService->getBalance($user);
    expect($balance)->toBe(70.00);
});

it('fails refund when payer info is missing', function () {
    // Create a transaction first
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    // Create payment without proper payer info in metadata
    $payment = PaymentTransaction::create([
        'transaction_id' => $transaction->id, // Add transaction_id
        'gateway_transaction_id' => 'test-payment-123',
        'gateway_name' => 'internal',
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => 'verified',
        'payment_method' => 'wallet',
        'payer_info' => [], // Empty payer info
        'metadata' => [],
    ]);
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $response = $gateway->refund($payment->gateway_transaction_id);
    
    expect($response->isSuccessful())->toBeFalse();
    expect($response->getErrorMessage())->toContain('Cannot refund');
});

it('fails refund when payer not found', function () {
    // Create a transaction first
    $transaction = Transaction::create(['status' => 'pending', 'total' => 0.0]);
    
    // Create payment with non-existent user
    $payment = PaymentTransaction::create([
        'transaction_id' => $transaction->id, // Add transaction_id
        'gateway_transaction_id' => 'test-payment-456',
        'gateway_name' => 'internal',
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => 'verified',
        'payment_method' => 'wallet',
        'payer_info' => [
            'owner_type' => User::class,
            'owner_id' => 999, // Non-existent user
        ],
        'metadata' => [],
    ]);
    
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $walletService = app(WalletService::class); // Fixed typo: WalletletService to WalletService
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $response = $gateway->refund($payment->gateway_transaction_id);
    
    expect($response->isSuccessful())->toBeFalse();
    expect($response->getErrorMessage())->toContain('Cannot refund');
});

it('handles webhook not supported', function () {
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    expect($gateway->supportsWebhook())->toBeFalse();
});

it('throws exception for webhook handling', function () {
    $gatewayModel = PaymentGateway::factory()->internal()->create();
    $walletService = app(WalletService::class);
    $gateway = new InternalGateway($gatewayModel, $walletService);
    
    $gateway->handleWebhook(['test' => 'payload']);
})->throws(\RuntimeException::class, 'Webhook not supported');
