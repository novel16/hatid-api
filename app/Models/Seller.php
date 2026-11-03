<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'contact', 'mode', 'fee'])]
class Seller extends Model
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory, SoftDeletes;

    /** Identifies the rider who owns these preferences. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Keeps fees in integer centavos. */
    protected function casts(): array
    {
        return ['fee' => 'integer'];
    }
}
