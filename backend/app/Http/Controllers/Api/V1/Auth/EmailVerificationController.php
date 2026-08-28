<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email verification for an API client.
 *
 * The link in the email points at the SPA (see AppServiceProvider), which then
 * calls `verify` with the signed parameters. Laravel's signature check is what
 * makes the route safe to leave unauthenticated — the URL cannot be forged and
 * expires on its own.
 */
final class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function verify(EmailVerificationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(message: __('This email address is already verified.'));
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        $this->audit->logFor('auth.email_verified', $user->tenant_id, $user);

        return ApiResponse::success(message: __('Your email address has been verified.'));
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(message: __('This email address is already verified.'));
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::success(message: __('A new verification link has been sent.'));
    }
}
