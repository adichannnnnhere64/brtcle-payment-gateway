<?php

namespace Adichan\Payment;

class PaymentWebhookResult implements \Adichan\Payment\Interfaces\PaymentWebhookResultInterface
{
    public function __construct(
        protected string $eventType,
        protected string $gatewayReference,
        protected array $payload,
        protected bool $shouldProcess,
        protected mixed $response
    ) {}

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getGatewayReference(): string
    {
        return $this->gatewayReference;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function shouldProcess(): bool
    {
        return $this->shouldProcess;
    }

    public function getResponse(): mixed
    {
        return $this->response;
    }
}
