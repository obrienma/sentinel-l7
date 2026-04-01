<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceEvent extends Model
{
    protected $fillable = [
        'source_id',
        'status',
        'metric_value',
        'anomaly_score',
        'emitted_at',
        'routed_to_ai',
        'audit_narrative',
        'driver_used',
    ];

    protected $casts = [
        'metric_value'  => 'float',
        'anomaly_score' => 'float',
        'routed_to_ai'  => 'boolean',
        'emitted_at'    => 'datetime',
    ];
}
