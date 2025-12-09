<?php

namespace Adichan\Transaction\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'itemable_id', 'itemable_type', 'quantity', 'price_at_time', 'subtotal'];

    protected $casts = [
        'price_at_time' => 'float',
        'subtotal' => 'float',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

}
