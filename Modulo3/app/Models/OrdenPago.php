<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenPago extends Model
{
    protected $table = 'm3_orden_pago';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'reservation_id',
        'buyer_id',
        'event_id',
        'seat_id',
        'amount_minor',
        'currency',
        'status',
        'buyer_email',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class, 'orden_pago_id', 'id');
    }
}
