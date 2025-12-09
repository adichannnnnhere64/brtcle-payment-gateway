<?php

namespace Adichan\Product\Models;

use Adichan\Product\Interfaces\ProductInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model implements ProductInterface
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'base_price' => 'decimal:4',
    ];

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

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
        return $this->variations;
    }

    public function getVariationPrice(array $attributes): float
    {
        $variation = $this->variations()
            ->whereJsonContains('attributes', $attributes)
            ->first();

        return $variation?->price_override ?? $this->getPrice();
    }

    public function applyRules(float $price): float
    {
        return $price; // Base; override in subclasses
    }
}
