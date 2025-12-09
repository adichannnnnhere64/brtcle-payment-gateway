<?php

namespace Adichan\Product\Products;

use Adichan\Product\Models\Product;

class CouponCodeProduct extends Product
{
    public function applyRules(float $price): float
    {
        return $price * 0.9; 
    }
}
