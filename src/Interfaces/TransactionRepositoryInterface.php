<?php

namespace Adichan\Transaction\Interfaces;

use Illuminate\Support\Collection;

interface TransactionRepositoryInterface
{
    public function find(int|string $id): ?TransactionInterface;

    public function all(): Collection;

    public function create(array $data): TransactionInterface;

    public function update(int|string $id, array $data): TransactionInterface;

    public function delete(int|string $id): bool;

    public function addItemToTransaction(int|string $transactionId, $itemable, int $quantity = 1, ?float $price = null): mixed;
}
