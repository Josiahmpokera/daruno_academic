<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeInvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'fee_category_id',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'invoice_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
