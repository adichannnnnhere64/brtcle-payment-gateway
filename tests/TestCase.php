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
        $this->loadMigrationsFrom(__DIR__.'/../vendor/adichan/product/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/adichan/transaction/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/adichan/wallet/src/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
        $this->createUsersTable();
        $this->createProductTable();
    }

    protected function createUsersTable(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamps();
            });
        }
    }

    protected function createProductTable(): void
    {
        if (! Schema::hasTable('products')) {
            /* Schema::create('products', function ($table) { */
            /*     $table->id(); */
            /*     $table->string('name'); */
            /*     $table->decimal('base_price', 12, 4); */
            /*     $table->string('type')->default('physical'); */
            /*     $table->json('meta')->nullable(); */
            /*     $table->timestamps(); */
            /* }); */
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Load testing environment
        $app->loadEnvironmentFrom('.env.testing');
        $app['config']->set('payment.mock_stripe', true);

        // Set up testing database
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Enable Stripe for testing
        $app['config']->set('payment.gateways.stripe.is_active', true);
        $app['config']->set('payment.default_gateway', 'stripe');

        // Set Stripe keys from environment
        $app['config']->set('payment.gateways.stripe.config.secret_key', env('STRIPE_SECRET_KEY'));
        $app['config']->set('payment.gateways.stripe.config.public_key', env('STRIPE_PUBLIC_KEY'));
        $app['config']->set('payment.gateways.stripe.config.webhook_secret', env('STRIPE_WEBHOOK_SECRET'));
    }
}
