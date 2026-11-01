<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    public function __construct(private readonly PasswordService $passwords) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => 'If an account exists, a password reset link will be sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json(['message' => 'Your password has been reset. Sign in again.']);
    }

    public function change(ChangePasswordRequest $request): Response
    {
        $this->passwords->change(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return response()->noContent();
    }
}
