<?php

namespace Adichan\Payment\Gateways;

use Adichan\Payment\Interfaces\PaymentGatewayInterface;
use Adichan\Payment\Interfaces\PaymentResponseInterface;
use Adichan\Payment\Interfaces\PaymentVerificationInterface;
use Adichan\Payment\Interfaces\PaymentWebhookResultInterface;
use Adichan\Payment\Models\PaymentGateway as GatewayModel;
use Adichan\Transaction\Interfaces\TransactionInterface;

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected GatewayModel $model;

    protected array $config;

    public function __construct(GatewayModel $model)
    {
        $this->model = $model;
        $this->config = $model->config ?? [];
    }

    public function getName(): string
    {
        return $this->model->name;
    }

    public function isEnabled(): bool
    {
        return $this->model->is_active ?? false;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    abstract public function initiatePayment(TransactionInterface $transaction, array $options = []): PaymentResponseInterface;

    abstract public function verifyPayment(string $paymentId, array $data = []): PaymentVerificationInterface;

    abstract public function refund(string $paymentId, ?float $amount = null): PaymentResponseInterface;

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function handleWebhook(array $payload): PaymentWebhookResultInterface
    {
        throw new \RuntimeException('Webhook not supported for this gateway');
    }

    protected function validateTransaction(TransactionInterface $transaction): void
    {
        if ($transaction->getTotal() <= 0) {
            throw new \InvalidArgumentException('Transaction amount must be greater than zero');
        }
    }
}
