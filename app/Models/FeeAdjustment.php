<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeAdjustment extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'invoice_id', 'type', 'reference',
        'title', 'amount', 'status', 'effective_on', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_on' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'invoice_id');
    }
}
