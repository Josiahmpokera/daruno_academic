<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    protected $fillable = [
        'school_id', 'vehicle_id', 'serviced_on', 'type', 'description',
        'cost', 'vendor', 'next_service_on', 'status', 'notes',
    ];

    protected $casts = [
        'serviced_on' => 'date',
        'next_service_on' => 'date',
        'cost' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
