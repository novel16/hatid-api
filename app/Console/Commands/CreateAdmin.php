<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\MaxPasswordBytes;
use App\Services\Auth\AuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create an admin account using hidden password prompts';

    public function handle(AuthService $auth): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively. Passwords are never accepted as command-line arguments.');

            return self::INVALID;
        }

        $data = [
            'name' => $this->ask('Name'),
            'email' => Str::lower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        try {
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
                'password' => ['required', 'string', 'confirmed', Password::defaults(), new MaxPasswordBytes],
            ])->validate();

            $auth->createAdmin($validated['name'], $validated['email'], $validated['password']);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info('Admin account created. Sign in through the admin API.');

        return self::SUCCESS;
    }
}
