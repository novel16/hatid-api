<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ParcelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['batch_id', 'customer_id', 'customer_address_id', 'seller_id', 'description', 'note', 'expected_method', 'fee_mode', 'quote', 'fee', 'customer_total', 'seller_share', 'override_reason'])]
class Parcel extends Model
{
    /** Share exact ready eligibility with lists and routing, including the due-hold boundary. */
    public function scopeReady(Builder $query): Builder
    {
        /** Failed and future-held work needs an explicit resume before delivery. */
        return $query->where(function (Builder $ready): void {
            $ready->where('status', 'pending')->orWhere(
                /** Scheduled holds become eligible at their exact retry instant. */
                fn (Builder $held) => $held->where('status', 'held')->where('retry_at', '<=', now()),
            );
        });
    }

    /** @use HasFactory<ParcelFactory> */
    use HasFactory;

    /** Identifies the rider whose private workspace contains this parcel. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Pins a parcel to its original intake week. */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** Existing work survives archiving its directory customer. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Address identity remains distinct across a customer's destinations. */
    public function address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id')->withTrashed();
    }

    /** Seller archives/default edits cannot erase the saved allocation. */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class)->withTrashed();
    }

    /** Provides append-only evidence of visits and explicit retry/return decisions. */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderByDesc('id');
    }

    /** Keeps money in centavos and evidence timestamps in UTC. */
    protected function casts(): array
    {
        return [
            'quote' => 'integer', 'fee' => 'integer', 'customer_total' => 'integer',
            'seller_share' => 'integer', 'received_amount' => 'integer',
            'retry_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
        ];
    }
}
