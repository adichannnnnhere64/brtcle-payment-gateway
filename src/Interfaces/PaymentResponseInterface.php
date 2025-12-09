<?php

namespace Adichan\Payment\Interfaces;

interface PaymentResponseInterface
{
    public function isSuccessful(): bool;

    public function getGatewayReference(): ?string;

    public function getRedirectUrl(): ?string;

    public function getErrorMessage(): ?string;

    public function getRawResponse(): array;

    public function requiresAction(): bool;

    public function getActionData(): ?array;
}
