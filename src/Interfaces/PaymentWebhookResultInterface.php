<?php

namespace Adichan\Payment\Interfaces;

interface PaymentWebhookResultInterface
{
    public function getEventType(): string;

    public function getGatewayReference(): string;

    public function getPayload(): array;

    public function shouldProcess(): bool;

    public function getResponse(): mixed;
}
