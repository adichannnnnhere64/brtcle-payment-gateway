<?php

namespace Adichan\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
	use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'config',
        'is_active',
        'is_external',
        'priority',
        'meta',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'is_external' => 'boolean',
        'meta' => 'array',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
