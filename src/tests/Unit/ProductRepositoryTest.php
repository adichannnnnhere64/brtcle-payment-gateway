<?php

use Adichan\Product\Interfaces\ProductRepositoryInterface;
use Adichan\Product\Models\Product;

beforeEach(function () {
    withPackageProviders();
    migratePackage();
});

it('can create and find a product', function () {
    $repo = app(ProductRepositoryInterface::class);
    $data = ['name' => 'Repo Test', 'base_price' => 5.99, 'type' => 'generic'];
    $product = $repo->create($data);

    $found = $repo->find($product->getId());
    expect($found->getName())->toBe('Repo Test');
});

it('can update a product', function () {
    $repo = app(ProductRepositoryInterface::class);
    $product = $repo->create(['name' => 'Old Name', 'base_price' => 1.0, 'type' => 'generic']);
    $updated = $repo->update($product->getId(), ['name' => 'New Name']);

    expect($updated->getName())->toBe('New Name');
});

it('can delete a product', function () {
    $repo = app(ProductRepositoryInterface::class);
    $product = $repo->create(['name' => 'To Delete', 'base_price' => 1.0, 'type' => 'generic']);
    $deleted = $repo->delete($product->getId());

    expect($deleted)->toBeTrue();
    expect($repo->find($product->getId()))->toBeNull();
});

it('can add variation via repo', function () {
    $repo = app(ProductRepositoryInterface::class);
    $product = $repo->create(['name' => 'With Variation', 'base_price' => 10.0, 'type' => 'generic']);
    $variationData = ['attributes' => json_encode(['flavor' => 'spicy']), 'price_override' => 12.0];
    $variation = $repo->addVariation($product->getId(), $variationData);

    expect($variation->price_override)->toBe(12.0);
});

it('can find by type', function () {
    $repo = app(ProductRepositoryInterface::class);
    Product::factory()->create(['type' => 'coupon']);
    Product::factory()->create(['type' => 'vegetable']);
    Product::factory()->create(['type' => 'coupon']);

    $coupons = $repo->findByType('coupon');
    expect($coupons->count())->toBe(2);
});
