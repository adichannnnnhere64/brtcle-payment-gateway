<?php

namespace Adichan\Payment;

use Adichan\Payment\Interfaces\PaymentServiceInterface;
use Adichan\Payment\Services\PaymentService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/payment.php', 'payment');

        $this->app->singleton(PaymentServiceInterface::class, function ($app) {
            return new PaymentService($app);
        });

        $this->app->alias(PaymentServiceInterface::class, 'payment');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/payment.php' => config_path('payment.php'),
            ], 'payment-config');

            $this->publishes([
                __DIR__.'/database/migrations' => database_path('migrations'),
            ], 'payment-migrations');
        }

        // Register default gateways
        $this->app->resolving(PaymentServiceInterface::class, function ($service) {
            $service->registerGateway('internal', Gateways\InternalGateway::class);
            $service->registerGateway('stripe', Gateways\StripeGateway::class);
            $service->registerGateway('paypal', Gateways\PayPalGateway::class);
        });
    }
}
