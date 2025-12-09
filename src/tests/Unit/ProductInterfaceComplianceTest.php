<?php

use Adichan\Product\Interfaces\ProductInterface;
use Adichan\Product\Models\Product;
use Adichan\Product\Products\CouponCodeProduct;

it('ensures all product classes implement interface', function ($class) {
    $instance = new $class;
    expect($instance)->toBeInstanceOf(ProductInterface::class);
})->with([
    Product::class,
    CouponCodeProduct::class,
]);
