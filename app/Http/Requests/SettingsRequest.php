<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    /** Route middleware requires a valid rider token with rider access. */
    public function authorize(): bool
    {
        return true;
    }

    /** Allows partial profile/preferences updates, excluding ownership and authentication fields. */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vehicle' => ['sometimes', 'required', 'string', Rule::in(User::VEHICLES)],
            'preferences' => ['sometimes', 'array:deliveryAlerts,collectionAlerts,compactView', 'min:1'],
            'preferences.deliveryAlerts' => ['sometimes', 'required', 'boolean'],
            'preferences.collectionAlerts' => ['sometimes', 'required', 'boolean'],
            'preferences.compactView' => ['sometimes', 'required', 'boolean'],
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
            'password' => ['prohibited'],
            'abilities' => ['prohibited'],
        ];
    }

    /** Trims identity text and permits clearing an optional contact number. */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'phone' && $value === '' ? null : $value]);
            }
        }
    }
}
