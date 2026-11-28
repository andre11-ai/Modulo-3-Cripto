<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockAsiento extends Model
{
    protected $table = 'm3_mock_asientos';

    protected $fillable = [
        'event_id',
        'seat_id',
        'status',
        'reservation_id',
        'locked_by',
        'locked_until',
        'amount_minor',
        'currency',
    ];

    protected $casts = [
        'locked_until' => 'datetime',
        'amount_minor' => 'integer',
    ];
}
