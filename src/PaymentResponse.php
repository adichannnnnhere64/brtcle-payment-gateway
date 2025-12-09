<?php

namespace Adichan\Payment;

class PaymentResponse implements \Adichan\Payment\Interfaces\PaymentResponseInterface
{
    public function __construct(
        protected bool $success,
        protected ?string $gatewayReference,
        protected ?string $redirectUrl,
        protected ?string $errorMessage,
        protected array $rawResponse,
        protected bool $requiresAction = false,
        protected ?array $actionData = null
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getGatewayReference(): ?string
    {
        return $this->gatewayReference;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    public function requiresAction(): bool
    {
        return $this->requiresAction;
    }

    public function getActionData(): ?array
    {
        return $this->actionData;
    }
}
