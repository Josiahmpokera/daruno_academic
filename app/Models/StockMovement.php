<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'stock_item_id', 'school_id', 'type', 'quantity', 'occurred_on',
        'reference', 'notes', 'unit_cost', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'occurred_on' => 'date',
        'unit_cost' => 'decimal:2',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
