<?php

namespace Adichan\Payment\Interfaces;

use Adichan\Transaction\Interfaces\TransactionInterface;

interface PaymentVerificationInterface
{
    public function isVerified(): bool;

    public function getTransaction(): ?TransactionInterface;

    public function getGateway(): string;

    public function getStatus(): string;

    public function getVerifiedAt(): ?\DateTimeInterface;

    public function getVerificationData(): array;
}
