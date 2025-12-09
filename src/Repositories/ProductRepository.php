<?php

namespace Adichan\ProductVariation\Repositories;

use Illuminate\Support\Collection;
use Adichan\ProductVariation\Interfaces\ProductInterface;
use Adichan\ProductVariation\Interfaces\ProductRepositoryInterface;
use Adichan\ProductVariation\Models\Product;
use Adichan\ProductVariation\Products\CouponCodeProduct;
use Adichan\ProductVariation\Products\VegetableProduct;

class ProductRepository implements ProductRepositoryInterface
{
    protected Product $model;

    public function __construct(Product $model)
    {
        $this->model = $model;
    }

    public function find(int|string $id): ?ProductInterface
    {
        $product = $this->model->find($id);
        return $product ? $this->instantiateByType($product) : null;
    }

    public function all(): Collection
    {
        return $this->model->all()->map(fn($p) => $this->instantiateByType($p));
    }

    public function create(array $data): ProductInterface
    {
        $product = $this->model->create($data);
        return $this->instantiateByType($product);
    }

    public function update(int|string $id, array $data): ProductInterface
    {
        $product = $this->model->findOrFail($id);
        $product->update($data);
        return $this->instantiateByType($product);
    }

    public function delete(int|string $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function addVariation(int|string $productId, array $variationData): mixed
    {
        $product = $this->model->findOrFail($productId);
        return $product->variations()->create($variationData);
    }

    public function findByType(string $type): Collection
    {
        return $this->model->where('type', $type)->get()->map(fn($p) => $this->instantiateByType($p));
    }

    protected function instantiateByType(Product $product): ProductInterface
    {
        return match ($product->type) {
            'coupon' => CouponCodeProduct::unguarded(fn() => new CouponCodeProduct($product->attributesToArray())),

            default => $product,
        };
    }
}
