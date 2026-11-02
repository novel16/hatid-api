<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Resources\Auth\AuthSessionResource;
use App\Http\Resources\Auth\TokenResource;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
        private readonly PasswordService $passwords,
    ) {}

    public function register(AuthRequest $request): JsonResponse
    {
        $token = $this->auth->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
        );

        return (new AuthSessionResource($token))->response()->setStatusCode(201);
    }

    public function login(AuthRequest $request): AuthSessionResource
    {
        return $this->authenticate($request, UserRole::Rider);
    }

    public function adminLogin(AuthRequest $request): AuthSessionResource
    {
        return $this->authenticate($request, UserRole::Admin);
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

    public function tokens(AuthRequest $request): AnonymousResourceCollection
    {
        return TokenResource::collection($this->tokens->paginate(
            $request->user(),
            $request->integer('per_page', 15),
        ));
    }

    public function revokeToken(Request $request, int $token): Response
    {
        $this->tokens->revokeOwned($request->user(), $token);

        return response()->noContent();
    }

    public function forgotPassword(AuthRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => 'If an account exists, a password reset link will be sent.',
        ]);
    }

    public function resetPassword(AuthRequest $request): JsonResponse
    {
        $this->passwords->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json(['message' => 'Your password has been reset. Sign in again.']);
    }

    public function changePassword(AuthRequest $request): Response
    {
        $this->passwords->change(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return response()->noContent();
    }

    private function authenticate(AuthRequest $request, UserRole $role): AuthSessionResource
    {
        return new AuthSessionResource($this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
            $role,
        ));
    }
}
