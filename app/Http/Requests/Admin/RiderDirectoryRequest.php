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

    /** Validate list filters, history paging and the confirmed status revision. */
    public function rules(): array
    {
        $page = ['sometimes', 'integer', 'between:1,2147483647'];
        $statuses = Rule::in(['active', 'suspended']);

        return match ($this->route()->getActionMethod()) {
            'index' => [
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'vehicle' => ['sometimes', 'nullable', 'string', Rule::in([...User::VEHICLES, 'unset'])],
                'status' => ['sometimes', 'nullable', 'string', $statuses],
                'page' => $page,
                'per_page' => ['sometimes', 'integer', Rule::in([10, 50, 100])],
            ],
            'activity' => ['page' => $page],
            'updateStatus' => [
                'status' => ['required', 'string', $statuses],
                'reason' => ['required', 'string', 'max:500'],
                'expected_revision' => ['required', 'integer', 'between:0,4294967294'],
            ],
        };
    }
}
