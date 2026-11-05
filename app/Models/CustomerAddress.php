<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['label', 'text'])]
class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory, SoftDeletes;

    /** Derives address ownership from the parent customer. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Casts delivery-verified GPS metadata without enabling directory mass assignment. */
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'verified_at' => 'immutable_datetime',
            'gps_accuracy' => 'float', 'gps_captured_at' => 'immutable_datetime',
            'location_version' => 'integer', 'location_corrected' => 'boolean'];
    }
}
