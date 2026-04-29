<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'txn_id',
        'merchant',
        'amount',
        'currency',
        'is_threat',
        'message',
        'source',
    ];

    protected $casts = [
        'is_threat' => 'boolean',
        'amount'    => 'decimal:2',
    ];
}
