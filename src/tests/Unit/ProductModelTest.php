<?php

use Adichan\Product\Interfaces\ProductRepositoryInterface;
use Adichan\Product\Models\Product;
use Adichan\Product\Models\ProductVariation;

beforeEach(function () {
    withPackageProviders();
    migratePackage();
});

it('can create a product', function () {
    $product = Product::factory()->create([
        'name' => 'Test Product',
        'base_price' => 10.99,
        'type' => 'generic',
    ]);

    expect($product->name)->toBe('Test Product');
    expect($product->getPrice())->toBe(10.99);
});

it('can add variations to a product', function () {
    $product = Product::factory()->create();
    $variation = ProductVariation::factory()->create([
        'product_id' => $product->id,
        'attributes' => json_encode(['color' => 'red']),
        'price_override' => 15.99,
    ]);

    expect($product->variations()->count())->toBe(1);
    expect($product->getVariationPrice(['color' => 'red']))->toBe(15.99);
});

it('falls back to base price if no variation override', function () {
    $product = Product::factory()->create(['base_price' => 10.99]);
    ProductVariation::factory()->create([
        'product_id' => $product->id,
        'attributes' => json_encode(['size' => 'large']),
        'price_override' => null,
    ]);

    expect($product->getVariationPrice(['size' => 'large']))->toBe(10.99);
});

it('applies rules correctly for subclasses', function () {
    $product = Product::factory()->create(['type' => 'coupon', 'base_price' => 100.0]);
    $repo = app(ProductRepositoryInterface::class);
    $instance = $repo->find($product->id);

    expect($instance->applyRules(100.0))->toBe(90.0);

    $product = Product::factory()->create(['type' => 'vegetable', 'base_price' => 100.0]);
    $instance = $repo->find($product->id);

    expect($instance->applyRules(100.0))->toBe(105.0);
});
