<?php

namespace Adichan\Product\Interfaces;

use Illuminate\Support\Collection;

interface ProductInterface
{
    public function getId(): int|string;

    public function getName(): string;

    public function getPrice(): float;

    public function getVariations(): Collection;

    public function getVariationPrice(array $attributes): float;

    public function applyRules(float $price): float;
}
