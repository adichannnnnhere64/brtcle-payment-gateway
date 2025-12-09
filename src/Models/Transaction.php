<?php

namespace Adichan\Transaction\Models;

use Adichan\Transaction\Interfaces\TransactionInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Transaction extends Model implements TransactionInterface
{
    use HasFactory;

    protected $fillable = ['status', 'total'];

    protected $casts = [
        'total' => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status ?? 'pending';
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem($itemable, int $quantity = 1, ?float $price = null): void
    {
        if ($price === null) {
            if (is_callable([$itemable, 'getPrice'])) {
                $price = $itemable->getPrice();
            } else {
                $price = 0.0;
            }
        }

        $this->items()->create([
            'itemable_id' => $itemable->getId(),
            'itemable_type' => get_class($itemable),
            'quantity' => $quantity,
            'price_at_time' => $price,
            'subtotal' => $price * $quantity,
        ]);

        $this->calculateTotal();
    }

    public function calculateTotal(): float
    {
        $this->loadMissing('items'); // ← THIS FIXES EVERYTHING

        $total = $this->items->sum('subtotal');

        $this->total = $total;
        $this->saveQuietly();

        return $this->total;
    }

    public function complete(): bool
    {
        return $this->update(['status' => 'completed']);
    }

    public function cancel(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }
}
