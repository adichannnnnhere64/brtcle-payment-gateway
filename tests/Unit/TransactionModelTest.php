<?php

use Adichan\Transaction\Models\Transaction;
use Adichan\Transaction\Models\TransactionItem;
use Mockery\MockInterface;

it('can create a transaction', function () {
    $transaction = Transaction::factory()->create([
        'status' => 'pending',
        'total' => 0.0,
    ]);

    expect($transaction->status)->toBe('pending');
    expect($transaction->getTotal())->toBe(0.0);
});

it('can add items to a transaction', function () {
    $transaction = Transaction::factory()->create();
    $itemable = mock('Adichan\Product\Models\Product')->makePartial();
    $itemable->shouldReceive('getId')->andReturn(1);
    $itemable->shouldReceive('getPrice')->andReturn(10.0);

    $transaction->addItem($itemable, 2);

    expect($transaction->items)->toHaveCount(1);
    expect($transaction->getTotal())->toBe(20.0);
});

it('can complete a transaction', function () {
    $transaction = Transaction::factory()->create(['status' => 'pending']);

    $transaction->complete();

    expect($transaction->getStatus())->toBe('completed');
});

it('can cancel a transaction', function () {
    $transaction = Transaction::factory()->create(['status' => 'pending']);

    $transaction->cancel();

    expect($transaction->getStatus())->toBe('cancelled');
});
