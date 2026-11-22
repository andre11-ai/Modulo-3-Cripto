<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaccionPago extends Model
{
    protected $table = 'm3_transaccion_pago';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'orden_pago_id',
        'stripe_payment_intent_id',
        'status',
        'failure_reason',
        'gateway_metadata',
    ];

    protected $casts = [
        'gateway_metadata' => 'array',
    ];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'orden_pago_id', 'id');
    }
}
