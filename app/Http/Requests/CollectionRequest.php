<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectionRequest extends FormRequest
{
    /** Middleware checks rider Bearer/role/ability; services resolve owned identities. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validates bounded money reads or one explicitly confirmed completed handover. */
    public function rules(): array
    {
        $protected = [
            'id' => ['prohibited'], 'user_id' => ['prohibited'], 'rider_id' => ['prohibited'],
            'allocations' => ['prohibited'], 'request_hash' => ['prohibited'], 'created_at' => ['prohibited'],
            'received_at' => ['prohibited'], 'recorded_at' => ['prohibited'],
        ];
        $action = $this->route()->getActionMethod();
        if (in_array($action, ['store', 'recover'], true)) {
            return [
                ...$protected,
                'operation_id' => ['required', 'uuid'], 'seller_id' => ['required', 'integer', 'min:1'],
                'amount' => ['required', 'integer:strict', 'between:1,9007199254740991'],
                'method' => ['required', Rule::in(['cash', 'gcash'])],
                'reference' => ['sometimes', 'nullable', 'string', 'max:200'],
                'handover_confirmed' => ['required', 'accepted'],
            ];
        }

        return [
            ...$protected, 'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $action === 'show' ? ['sometimes', 'integer', 'in:10'] : ['sometimes', 'integer', 'between:1,100'],
            'method' => ['sometimes', Rule::in(['all', 'cash', 'gcash'])],
            'balance' => ['sometimes', Rule::in(['all', 'outstanding', 'settled'])],
        ];
    }

    /** Canonicalizes human search/reference text without coercing money types. */
    protected function prepareForValidation(): void
    {
        foreach (['q', 'reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
