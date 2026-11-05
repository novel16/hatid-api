<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchRequest extends FormRequest
{
    /** Shared middleware requires a valid rider token and rider access ability. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validates list queries or a new intake range, without accepting ownership/progress fields. */
    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'index') {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ];
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'rider_id' => ['prohibited'],
            'status' => ['prohibited'],
            'parcels' => ['prohibited'],
        ];
    }

    /** Trims human-entered names/search without converting dates through a timezone. */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'q'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
