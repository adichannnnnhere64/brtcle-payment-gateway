<?php

namespace Adichan\Payment\Services;

use Adichan\Payment\Interfaces\PaymentGatewayInterface;
use Adichan\Payment\Interfaces\PaymentResponseInterface;
use Adichan\Payment\Interfaces\PaymentServiceInterface;
use Adichan\Payment\Interfaces\PaymentVerificationInterface;
use Adichan\Payment\Interfaces\PaymentWebhookResultInterface;
use Adichan\Payment\Models\PaymentGateway as GatewayModel;
use Adichan\Transaction\Interfaces\TransactionInterface;
use Illuminate\Support\Manager;

class PaymentService extends Manager implements PaymentServiceInterface
{
    protected string $defaultGateway;

    protected array $gateways = [];

    public function getDefaultDriver(): string
    {
        return $this->defaultGateway ?? config('payment.default_gateway', 'internal');
    }

    public function gateway(?string $name = null): PaymentGatewayInterface
    {
        return $this->driver($name);
    }

    public function setGateway(string $name): self
    {
        $this->defaultGateway = $name;

        return $this;
    }

    public function pay(TransactionInterface $transaction, array $options = []): PaymentResponseInterface
    {
        $gateway = $this->gateway($options['gateway'] ?? null);

        if (! $gateway->isEnabled()) {
            throw new \RuntimeException("Gateway {$gateway->getName()} is not enabled");
        }

        return $gateway->initiatePayment($transaction, $options);
    }

    public function verify(string $paymentId, array $data = []): PaymentVerificationInterface
    {
        $gatewayName = $data['gateway'] ?? $this->getDefaultDriver();
        $gateway = $this->gateway($gatewayName);

        return $gateway->verifyPayment($paymentId, $data);
    }

    public function processWebhook(string $gatewayName, array $payload): PaymentWebhookResultInterface
    {
        $gateway = $this->gateway($gatewayName);

        if (! $gateway->supportsWebhook()) {
            throw new \RuntimeException("Gateway {$gatewayName} does not support webhooks");
        }

        return $gateway->handleWebhook($payload);
    }

    public function getAvailableGateways(): array
    {
        return GatewayModel::where('is_active', true)
            ->orderBy('priority')
            ->get()
            ->mapWithKeys(fn ($gateway) => [$gateway->name => $gateway->driver])
            ->toArray();
    }

    public function registerGateway(string $name, string $gatewayClass): void
    {
        $this->extend($name, function ($app) use ($gatewayClass, $name) {
            $gatewayModel = GatewayModel::where('name', $name)->first();

            if (! $gatewayModel) {
                throw new \RuntimeException("Gateway configuration not found for: {$name}");
            }

            /* return new $gatewayClass($gatewayModel); */
            return $app->make($gatewayClass, ['model' => $gatewayModel]);
        });
    }

    protected function createInternalDriver(): PaymentGatewayInterface
    {
        return $this->app->make(\Adichan\Payment\Gateways\InternalGateway::class);
    }

    protected function createStripeDriver(): PaymentGatewayInterface
    {
        return $this->app->make(\Adichan\Payment\Gateways\StripeGateway::class);
    }

    protected function createPaypalDriver(): PaymentGatewayInterface
    {
        return $this->app->make(\Adichan\Payment\Gateways\PayPalGateway::class);
    }

    // Add more driver creation methods as needed
}
