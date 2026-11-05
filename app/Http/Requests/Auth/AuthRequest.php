<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\MaxPasswordBytes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Validate the current auth action, requiring an explicit allowed vehicle only for rider signup.
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $email = ['required', 'string', 'email:rfc', 'max:255'];
        $password = ['required', 'string', new MaxPasswordBytes];
        $deviceName = ['required', 'string', 'max:100'];

        return match ($this->route()->getActionMethod()) {
            'register' => [
                'name' => ['required', 'string', 'max:255'],
                'vehicle' => ['required', 'string', Rule::in(User::VEHICLES)],
                'email' => [...$email, Rule::unique(User::class, 'email')],
                'password' => $this->newPasswordRules(),
                'device_name' => $deviceName,
                'role' => ['prohibited'],
                'is_admin' => ['prohibited'],
                'abilities' => ['prohibited'],
            ],
            'login', 'adminLogin' => [
                'email' => $email,
                'password' => $password,
                'device_name' => $deviceName,
            ],
            'forgotPassword' => ['email' => $email],
            'resetPassword' => [
                'email' => $email,
                'token' => ['required', 'string', 'max:255'],
                'password' => $this->newPasswordRules(),
            ],
            'changePassword' => [
                'current_password' => $password,
                'password' => $this->newPasswordRules(['different:current_password']),
            ],
            'tokens' => [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ],
        };
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => User::normalizeEmail($email)]);
        }
    }

    /**
     * @param  array<string>  $extra
     * @return array<mixed>
     */
    private function newPasswordRules(array $extra = []): array
    {
        return ['required', 'string', 'confirmed', ...$extra, Password::defaults(), new MaxPasswordBytes];
    }
}
