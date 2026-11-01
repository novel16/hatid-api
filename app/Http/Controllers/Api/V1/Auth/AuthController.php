<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthSessionResource;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $session = $this->auth->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
        );

        return (new AuthSessionResource($session))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): AuthSessionResource
    {
        return new AuthSessionResource($this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
            UserRole::Rider,
        ));
    }

    public function adminLogin(LoginRequest $request): AuthSessionResource
    {
        return new AuthSessionResource($this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
            UserRole::Admin,
        ));
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $this->tokens->revokeCurrent($request->user());

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $this->tokens->revokeAll($request->user());

        return response()->noContent();
    }
}
