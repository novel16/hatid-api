<?php

declare(strict_types=1);

namespace App\Http\Requests\Directory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SellerRequest extends FormRequest
{
    /** Middleware and scoped services enforce rider access to seller records. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validates explicit fee defaults and bounded listing parameters. */
    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'index') {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'archived' => ['sometimes', 'boolean'],
            ];
        }
        $presence = $this->route()->getActionMethod() === 'update' ? ['sometimes', 'required'] : ['required'];

        return [
            'id' => ['prohibited'], 'user_id' => ['prohibited'], 'rider_id' => ['prohibited'],
            'deleted_at' => ['prohibited'], 'archived_at' => ['prohibited'],
            'name' => [...$presence, 'string', 'min:2', 'max:100'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mode' => [...$presence, Rule::in(['included', 'excluded'])],
            'fee' => [...$presence, 'integer:strict', 'min:0', 'max:9007199254740991'],
        ];
    }

    /** Normalizes display/search fields without changing the integer fee units. */
    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['name', 'contact', 'q'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed = trim($this->input($field));
                $values[$field] = $trimmed === '' && $field !== 'name' ? null : $trimmed;
            }
        }
        $this->merge($values);
    }
}
