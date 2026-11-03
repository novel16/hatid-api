<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    /** Removes cosmetic phone formatting while preserving digits and the international prefix. */
    public static function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s()\-]+/u', '', trim($phone));

        return $phone === '' ? null : $phone;
    }

    /** Identifies the rider who owns this private customer. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Keeps delivery addresses attached to their customer. */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
