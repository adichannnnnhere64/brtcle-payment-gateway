<?php

namespace Adichan\Payment\Tests\TestModels;

use Adichan\Product\Interfaces\ProductInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Product extends Model implements ProductInterface
{
    protected $table = 'products';

    protected $fillable = ['name', 'base_price', 'type', 'meta'];

    protected $casts = [
        'meta' => 'array',
        'base_price' => 'decimal:4',
    ];

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): float
    {
        return (float) $this->base_price;
    }

    public function getVariations(): Collection
    {
        return collect();
    }

    public function getVariationPrice(array $attributes): float
    {
        return $this->getPrice();
    }

    public function applyRules(float $price): float
    {
        return $price;
    }
}
