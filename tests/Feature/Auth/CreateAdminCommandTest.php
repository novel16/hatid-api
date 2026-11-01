<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommandTest extends AuthTestCase
{
    public function test_admin_provisioning_uses_hidden_prompts_and_hashes_the_password(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Hatid Admin')
            ->expectsQuestion('Email', 'ADMIN@EXAMPLE.COM')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutput('Admin account created. Sign in through the admin API.')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_provisioning_does_not_promote_an_existing_rider(): void
    {
        $rider = $this->account();

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Admin')
            ->expectsQuestion('Email', $rider->email)
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(UserRole::Rider, $rider->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_provisioning_rejects_weak_passwords(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_provisioning_cannot_silently_create_default_credentials(): void
    {
        $this->artisan('app:create-admin', ['--no-interaction' => true])->assertExitCode(2);
        $this->assertDatabaseCount('users', 0);
    }
}
