<?php

use Adichan\Transaction\Interfaces\TransactionRepositoryInterface;
use Adichan\Transaction\Models\Transaction;
use Mockery\MockInterface;

it('can create and find a transaction', function () {
    $repo = app(TransactionRepositoryInterface::class);
    $data = ['status' => 'pending', 'total' => 0.0];
    $transaction = $repo->create($data);

    $found = $repo->find($transaction->getId());
    expect($found->getStatus())->toBe('pending');
});

it('can update a transaction', function () {
    $repo = app(TransactionRepositoryInterface::class);
    $transaction = $repo->create(['status' => 'pending']);
    $updated = $repo->update($transaction->getId(), ['status' => 'completed']);

    expect($updated->getStatus())->toBe('completed');
});

it('can delete a transaction', function () {
    $repo = app(TransactionRepositoryInterface::class);
    $transaction = $repo->create(['status' => 'pending']);
    $deleted = $repo->delete($transaction->getId());

    expect($deleted)->toBeTrue();
    expect($repo->find($transaction->getId()))->toBeNull();
});

it('can add item via repo', function () {
    $repo = app(TransactionRepositoryInterface::class);
    $transaction = $repo->create(['status' => 'pending']);
    
    $itemable = mock('Adichan\Product\Models\Product')->makePartial();
    $itemable->shouldReceive('getId')->andReturn(1);
    $itemable->shouldReceive('getPrice')->andReturn(12.0);
    
    $item = $repo->addItemToTransaction($transaction->getId(), $itemable, 1);
    
    expect($item->price_at_time)->toBe(12.0);
    expect($transaction->fresh()->getTotal())->toBe(12.0);
});
