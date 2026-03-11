<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceConfig extends Model
{
    protected $fillable = [
        'user_id',
        'bank_name',
        'closing_day',
        'is_active',
    ];

    protected $casts = [
        'closing_day' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
