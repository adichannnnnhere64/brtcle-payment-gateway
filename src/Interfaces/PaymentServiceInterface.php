<?php

namespace Adichan\Payment\Interfaces;

use Adichan\Transaction\Interfaces\TransactionInterface;

interface PaymentServiceInterface
{
    public function gateway(?string $name = null): PaymentGatewayInterface;

    public function setGateway(string $name): self;

    public function pay(TransactionInterface $transaction, array $options = []): PaymentResponseInterface;

    public function verify(string $paymentId, array $data = []): PaymentVerificationInterface;

    public function processWebhook(string $gatewayName, array $payload): PaymentWebhookResultInterface;

    public function getAvailableGateways(): array;

    public function registerGateway(string $name, string $gatewayClass): void;
}
