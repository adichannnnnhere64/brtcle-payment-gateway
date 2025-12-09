<?php

namespace Adichan\Payment\Tests;

use Adichan\Payment\PaymentServiceProvider;
use Adichan\Transaction\TransactionServiceProvider;
use Adichan\Wallet\WalletServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            PaymentServiceProvider::class,
            WalletServiceProvider::class,
            TransactionServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../Product/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../Transaction/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../Wallet/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
        $this->createUsersTable();
        $this->createProductTable();

    }

    protected function createUsersTable(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    protected function createProductTable(): void
    {
        if (Schema::hasTable('products')) {
            Schema::create('products', function ($table) {
                $table->id();
                $table->string('name');
                $table->decimal('base_price', 12, 4);
                $table->timestamps();
            });
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payment.gateways.internal.is_active', true);
        $app['config']->set('payment.default_gateway', 'internal');
    }
}
