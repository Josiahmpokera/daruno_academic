<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolSubscription extends Model
{
    protected $fillable = [
        'school_id',
        'plan_id',
        'status',
        'starts_on',
        'ends_on',
        'trial_ends_on',
        'auto_renew',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'trial_ends_on' => 'date',
        'auto_renew' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class, 'subscription_id');
    }
}
