<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /** Enforce role and current rider status for every protected request. */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role === UserRole::tryFrom($role), 403);
        abort_if($user->isSuspendedRider(), 403, 'This rider account is suspended.');

        return $next($request);
    }
}
