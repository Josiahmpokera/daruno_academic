<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = [
        'budget_id', 'coa_id', 'label', 'amount', 'period',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }
}
