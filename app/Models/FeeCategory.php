<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeCategory extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'code',
        'description',
        'is_compulsory',
        'is_recurring',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'is_recurring' => 'boolean',
    ];

    public function structures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }
}
