<?php

namespace Adichan\Transaction;

use Adichan\Transaction\Interfaces\TransactionRepositoryInterface;
use Adichan\Transaction\Repositories\TransactionRepository;
use Illuminate\Support\ServiceProvider;

class TransactionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'transaction-migrations');

            $this->publishes([
                __DIR__.'/../database/factories' => database_path('factories/Adichan/Transaction'),
            ], 'transaction-factories');
        }
    }

    public function register(): void
    {
        $this->app->bind(TransactionRepositoryInterface::class, TransactionRepository::class);
    }
}
