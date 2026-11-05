<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['seller_id', 'operation_id', 'request_hash', 'amount', 'method', 'reference'])]
class Remittance extends Model
{
    /** Archived seller identity remains visible on an immutable handover. */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class)->withTrashed();
    }

    /** Each pivot stores the exact seller share allocated in this handover. */
    public function parcels(): BelongsToMany
    {
        return $this->belongsToMany(Parcel::class, 'remittance_allocations')->withPivot('amount');
    }

    /** Money is always an integer number of centavos. */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
