<?php

namespace Adichan\Payment\Gateways;

use Adichan\Payment\Interfaces\PaymentResponseInterface;
use Adichan\Payment\Interfaces\PaymentVerificationInterface;
use Adichan\Payment\Models\PaymentGateway as GatewayModel;
use Adichan\Payment\Models\PaymentTransaction;
use Adichan\Payment\PaymentResponse;
use Adichan\Payment\PaymentVerification;
use Adichan\Transaction\Interfaces\TransactionInterface;
use Adichan\Wallet\Services\WalletService;
use Illuminate\Database\Eloquent\Model;

class InternalGateway extends AbstractGateway
{
    protected WalletService $walletService;

    public function __construct(GatewayModel $model, WalletService $walletService)
    {
        parent::__construct($model);
        $this->walletService = $walletService;
    }

    public function initiatePayment(TransactionInterface $transaction, array $options = []): PaymentResponseInterface
    {
        $this->validateTransaction($transaction);

        // Get payer from options or default
        $payer = $options['payer'] ?? null;

        if (! $payer instanceof Model) {
            throw new \InvalidArgumentException('Payer model is required for internal payments');
        }

        // Check if payer has sufficient balance
        $balance = $this->walletService->getBalance($payer);

        if ($balance < $transaction->getTotal()) {
            return new PaymentResponse(
                false,
                null,
                null,
                'Insufficient wallet balance',
                []
            );
        }

        // Create payment record with gateway_transaction_id
        $payment = PaymentTransaction::create([
            'gateway_id' => $this->model->id,
            'transaction_id' => $transaction->getId(),
            'gateway_transaction_id' => 'internal_'.uniqid(), // Add this line
            'gateway_name' => $this->getName(),
            'amount' => $transaction->getTotal(),
            'currency' => $options['currency'] ?? 'USD',
            'status' => 'pending',
            'payment_method' => 'wallet',
            'payer_info' => [
                'owner_type' => get_class($payer),
                'owner_id' => $payer->getKey(),
            ],
            'metadata' => $options,
        ]);

        // Deduct from wallet
        $this->walletService->deductFunds(
            $payer,
            $transaction->getTotal(),
            "Payment for transaction #{$transaction->getId()}"
        );

        // Complete the transaction
        $transaction->complete();

        $payment->markAsVerified();

        return new PaymentResponse(
            true,
            $payment->gateway_transaction_id, // Return gateway_transaction_id, not payment->id
            null,
            null,
            ['payment_id' => $payment->id, 'balance_after' => $this->walletService->getBalance($payer)]
        );
    }

    public function verifyPayment(string $paymentId, array $data = []): PaymentVerificationInterface
    {
        $payment = PaymentTransaction::where('gateway_transaction_id', $paymentId)
            ->orWhere('id', $paymentId)
            ->firstOrFail();

        return new PaymentVerification(
            true,
            $payment->transaction,
            $this->getName(),
            'verified',
            now(),
            ['payment' => $payment->toArray()]
        );
    }

    public function refund(string $paymentId, ?float $amount = null): PaymentResponseInterface
    {
        // Find by gateway_transaction_id first, then by id
        $payment = PaymentTransaction::where('gateway_transaction_id', $paymentId)
            ->orWhere('id', $paymentId)
            ->first(); // Use first() instead of findOrFail()

        if (! $payment) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                'Payment record not found',
                ['payment_id' => $paymentId]
            );
        }

        if (! $amount) {
            $amount = $payment->amount;
        }

        // Get payer info
        $payerInfo = $payment->payer_info ?? [];

        if (empty($payerInfo['owner_type']) || empty($payerInfo['owner_id'])) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                'Cannot refund: payer information missing',
                []
            );
        }

        // Refund to wallet
        $ownerClass = $payerInfo['owner_type'];
        $owner = $ownerClass::find($payerInfo['owner_id']);

        if (! $owner) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                'Cannot refund: payer not found',
                []
            );
        }

        $this->walletService->addFunds(
            $owner,
            $amount,
            "Refund for payment #{$paymentId}"
        );

        // Update payment status
        $payment->update([
            'status' => 'refunded',
            'metadata' => array_merge($payment->metadata ?? [], [
                'refunded_at' => now()->toIso8601String(),
                'refund_amount' => $amount,
            ]),
        ]);

        return new \Adichan\Payment\PaymentResponse(
            true,
            $paymentId,
            null,
            null,
            ['refund_amount' => $amount, 'payment' => $payment->toArray()]
        );
    }
}
