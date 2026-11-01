<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\MaxPasswordBytes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', new MaxPasswordBytes],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults(), new MaxPasswordBytes],
        ];
    }
}
