<?php

namespace Adichan\Transaction\Repositories;

use Adichan\Transaction\Interfaces\TransactionInterface;
use Adichan\Transaction\Interfaces\TransactionRepositoryInterface;
use Adichan\Transaction\Models\Transaction;
use Illuminate\Support\Collection;

class TransactionRepository implements TransactionRepositoryInterface
{
    protected Transaction $model;

    public function __construct(Transaction $model)
    {
        $this->model = $model;
    }

    public function find(int|string $id): ?TransactionInterface
    {
        return $this->model->find($id);
    }

    public function all(): Collection
    {
        return $this->model->all();
    }

    public function create(array $data): TransactionInterface
    {
        return $this->model->create($data);
    }

    public function update(int|string $id, array $data): TransactionInterface
    {
        $transaction = $this->model->findOrFail($id);
        $transaction->update($data);

        return $transaction;
    }

    public function delete(int|string $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function addItemToTransaction(int|string $transactionId, $itemable, int $quantity = 1, ?float $price = null): mixed
    {
        $transaction = $this->find($transactionId);
        $transaction->addItem($itemable, $quantity, $price);

        return $transaction->items()->latest()->first();
    }
}
