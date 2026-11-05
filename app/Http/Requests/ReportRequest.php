<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    /** Existing middleware enforces rider Bearer, role and ability before private reports. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validates actual calendar dates and bounded page/search inputs without accepting ownership. */
    public function rules(): array
    {
        $protected = ['user_id' => ['prohibited'], 'rider_id' => ['prohibited'], 'timezone' => ['prohibited']];
        if ($this->route()->getActionMethod() === 'dashboard') {
            return [...$protected, 'period' => ['prohibited'], 'date' => ['prohibited'], 'start' => ['prohibited'], 'end' => ['prohibited']];
        }
        $custom = $this->input('period', 'week') === 'custom';

        return [
            ...$protected,
            'period' => ['sometimes', Rule::in(['day', 'week', 'month', 'custom'])],
            'date' => $custom ? ['prohibited'] : ['sometimes', 'date_format:Y-m-d'],
            'start' => $custom ? ['required', 'date_format:Y-m-d'] : ['prohibited'],
            'end' => $custom ? ['required', 'date_format:Y-m-d', 'after_or_equal:start'] : ['prohibited'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'method' => ['sometimes', Rule::in(['all', 'cash', 'gcash'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** Normalize search text only; preserve native date values for validation and redisplay. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
