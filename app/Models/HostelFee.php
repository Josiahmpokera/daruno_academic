<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelFee extends Model
{
    protected $fillable = [
        'school_id', 'hostel_id', 'title', 'amount', 'period', 'status', 'notes',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }
}
