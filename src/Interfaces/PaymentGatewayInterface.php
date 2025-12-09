<?php

namespace Adichan\Payment\Interfaces;

use Adichan\Transaction\Interfaces\TransactionInterface;

interface PaymentGatewayInterface
{
    public function getName(): string;

    public function isEnabled(): bool;

    public function initiatePayment(TransactionInterface $transaction, array $options = []): PaymentResponseInterface;

    public function verifyPayment(string $paymentId, array $data = []): PaymentVerificationInterface;

    public function refund(string $paymentId, ?float $amount = null): PaymentResponseInterface;

    public function supportsWebhook(): bool;

    public function handleWebhook(array $payload): PaymentWebhookResultInterface;

    public function getConfig(): array;
}
