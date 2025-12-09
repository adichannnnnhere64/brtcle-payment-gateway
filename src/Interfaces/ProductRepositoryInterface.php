<?php

namespace Adichan\Product\Interfaces;

use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    public function find(int|string $id): ?ProductInterface;

    public function all(): Collection;

    public function create(array $data): ProductInterface;

    public function update(int|string $id, array $data): ProductInterface;

    public function delete(int|string $id): bool;

    public function addVariation(int|string $productId, array $variationData): mixed;

    public function findByType(string $type): Collection;
}
