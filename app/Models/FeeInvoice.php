<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeInvoice extends Model
{
    protected $fillable = [
        'school_id',
        'student_id',
        'academic_year_id',
        'term_id',
        'reference',
        'total_amount',
        'balance_amount',
        'status',
        'issue_date',
        'due_date',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class, 'invoice_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
