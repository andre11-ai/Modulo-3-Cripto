<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoWebhook extends Model
{
    protected $table = 'm3_evento_webhook';

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'stripe_payment_intent_id',
        'payload',
        'processed',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];
}
