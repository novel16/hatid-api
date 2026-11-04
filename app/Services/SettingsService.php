<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class SettingsService
{
    /**
     * Merges partial preferences under an account lock so independent saves cannot overwrite each other.
     *
     * @param  array{name?: string, phone?: ?string, preferences?: array<string, bool|int|string>}  $values
     */
    public function update(User $user, array $values): User
    {
        return DB::transaction(function () use ($user, $values): User {
            $account = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $changes = Arr::only($values, ['name', 'phone']);
            if (isset($values['preferences'])) {
                $changes['preferences'] = array_replace($account->settingPreferences(), $values['preferences']);
            }
            $account->forceFill($changes)->save();

            return $account;
        });
    }
}
