<?php

namespace Adichan\Transaction\Interfaces;

use Illuminate\Support\Collection;

interface TransactionInterface
{
    public function getId(): int|string;

    public function getStatus(): string;

    public function getTotal(): float;

    public function getItems(): Collection;

    public function addItem($itemable, int $quantity = 1, ?float $price = null): void;

    public function calculateTotal(): float;

    public function complete(): bool;

    public function cancel(): bool;
}
