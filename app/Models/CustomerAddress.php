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

    /** Casts read-only GPS data without enabling mass assignment. */
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'verified_at' => 'datetime'];
    }
}
