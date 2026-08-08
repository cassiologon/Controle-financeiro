<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialInsight extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'data_hash',
        'summary',
        'total_potential_savings',
        'insights',
        'generated_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_potential_savings' => 'decimal:2',
        'insights' => 'array',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
