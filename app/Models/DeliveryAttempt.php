<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation_id', 'request_hash', 'outcome', 'note', 'retry_at'])]
class DeliveryAttempt extends Model
{
    /** Derives ownership from the immutable parcel parent. */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    /** Retains a retry schedule as evidence even after the parcel is resumed. */
    protected function casts(): array
    {
        return ['retry_at' => 'immutable_datetime'];
    }
}
