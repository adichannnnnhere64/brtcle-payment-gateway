<?php

namespace Adichan\Payment\Models;

use Adichan\Transaction\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentTransaction extends Model
{
	use HasFactory;

    protected $fillable = [
        'gateway_id',
        'transaction_id',
        'gateway_transaction_id',
        'gateway_name',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payer_info',
        'metadata',
        'verified_at',
        'webhook_received',
    ];

    protected $casts = [
        'amount' => 'float',
        'payer_info' => 'array',
        'metadata' => 'array',
        'webhook_received' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function markAsVerified(array $data = []): self
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $data),
        ]);

        return $this;
    }

    public function markAsFailed(string $reason): self
    {
        $this->update([
            'status' => 'failed',
            'metadata' => array_merge($this->metadata ?? [], ['failure_reason' => $reason]),
        ]);

        return $this;
    }
}
