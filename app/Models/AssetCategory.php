<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    protected $fillable = ['school_id', 'name', 'code'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class, 'category_id');
    }
}
