<?php

namespace Adichan\Payment;

class PaymentVerification implements \Adichan\Payment\Interfaces\PaymentVerificationInterface
{
    public function __construct(
        protected bool $verified,
        protected ?\Adichan\Transaction\Interfaces\TransactionInterface $transaction,
        protected string $gateway,
        protected string $status,
        protected ?\DateTimeInterface $verifiedAt,
        protected array $verificationData
    ) {}

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getTransaction(): ?\Adichan\Transaction\Interfaces\TransactionInterface
    {
        return $this->transaction;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function getVerificationData(): array
    {
        return $this->verificationData;
    }
}
