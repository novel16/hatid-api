<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationRequest extends FormRequest
{
    /** Existing middleware requires a real rider Bearer; services enforce record ownership. */
    public function authorize(): bool
    {
        return true;
    }

    /** Accept bounded list controls, never client ownership, read timestamps or notification data. */
    public function rules(): array
    {
        $rules = array_fill_keys(['user_id', 'rider_id', 'notifiable_id', 'notifiable_type', 'read_at', 'data', 'target', 'id'], ['prohibited']);
        if ($this->route()->getActionMethod() === 'index') {
            $rules += [
                'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'state' => ['sometimes', Rule::in(['all', 'unread'])],
            ];
        }

        return $rules;
    }
}
