<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwords,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Always answers 200 with the same message, whether or not the address is
     * on file. Anything else turns this endpoint into a way to find out who
     * holds an account at a given school.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink(
            $request->string('email')->toString(),
            $this->context->id(),
        );

        return ApiResponse::success(message: __(
            'If an account matches that email address, a password reset link has been sent.'
        ));
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $succeeded = $this->passwords->reset(
            $request->string('email')->toString(),
            $this->context->id(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        if (! $succeeded) {
            throw ValidationException::withMessages([
                'token' => __('This password reset link is invalid or has expired.'),
            ]);
        }

        return ApiResponse::success(message: __('Your password has been reset. Please sign in.'));
    }

    /**
     * Change your own password while signed in.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentToken = $user->currentAccessToken();

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke every other credential but keep the one making this request,
        // so changing a password does not sign you out of the tab you are in.
        $user->tokens()
            ->when(
                $currentToken instanceof \Laravel\Sanctum\PersonalAccessToken,
                fn ($q) => $q->whereKeyNot($currentToken->getKey()),
            )
            ->delete();

        $this->audit->logFor('auth.password_changed', $user->tenant_id, $user);

        return ApiResponse::success(message: __('Your password has been updated.'));
    }
}
