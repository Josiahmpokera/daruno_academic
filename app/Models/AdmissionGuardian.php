<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionGuardian extends Model
{
    protected $fillable = [
        'admission_id',
        'name',
        'relationship',
        'phone',
        'email',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
