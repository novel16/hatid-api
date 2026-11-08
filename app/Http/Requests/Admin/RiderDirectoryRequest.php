<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiderDirectoryRequest extends FormRequest
{
    /** The route checks the admin role and token ability before validation. */
    public function authorize(): bool
    {
        return true;
    }

    /** Bound search and paging and allow a filter for profiles with no vehicle. */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle' => ['sometimes', 'nullable', 'string', Rule::in([...User::VEHICLES, 'unset'])],
            'page' => ['sometimes', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 50, 100])],
        ];
    }
}
