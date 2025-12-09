<?php

namespace Adichan\Product;

use Adichan\Product\Interfaces\ProductRepositoryInterface;
use Adichan\Product\Repositories\ProductRepository;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'product');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'product');
        /* $this->loadMigrationsFrom(__DIR__.'/../database/migrations'); */
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('product.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], ['migrations', 'product-variation-migrations']);

            $this->publishes([
                __DIR__.'/../database/factories' => database_path('factories'),
            ], ['factories', 'product-variation-factories']);

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/product'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/product'),
            ], 'assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/product'),
            ], 'lang');*/

            // Registering package commands.
            // $this->commands([]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'product');

        // Register the main class to use with the facade
        $this->app->singleton('product', function () {
            return new Product;
        });

        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
    }
}
