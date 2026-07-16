<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    protected $fillable = [
        'school_id',
        'student_id',
        'invoice_id',
        'amount',
        'method',
        'reference',
        'paid_at',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
