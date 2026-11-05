<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = ['role' => 'rider'];

    /** Provides the owner's customer scope for every directory operation. */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Provides the owner's seller scope without exposing another rider's preferences. */
    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class);
    }

    /** Scopes every intake batch to its owning rider. */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /** Allows only known preferences and provides defaults for existing accounts. */
    public function settingPreferences(): array
    {
        $preferences = $this->preferences ?? [];

        return [
            'deliveryAlerts' => (bool) ($preferences['deliveryAlerts'] ?? true),
            'collectionAlerts' => (bool) ($preferences['collectionAlerts'] ?? true),
            'compactView' => (bool) ($preferences['compactView'] ?? false),
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'preferences' => 'array',
        ];
    }
}
