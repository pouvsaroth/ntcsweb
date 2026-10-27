<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Services\SchoolOwners;
use App\Rules\UniqueAccountIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The school's owner: resend the invite, or invite a different owner. Never a password. */
final class SchoolOwnerController extends Controller
{
    public function __construct(private readonly SchoolOwners $owners) {}

    public function resendInvite(Tenant $school): JsonResponse
    {
        $this->owners->invite($school);

        return ApiResponse::success(null, __('Invite sent.'));
    }

    /** A new link to copy and send by hand (no email). The previous link stops working. */
    public function inviteLink(Tenant $school): JsonResponse
    {
        return ApiResponse::success([
            'url' => $this->owners->copyableLink($school),
            'expires_in_days' => (int) config('platform.invite_days', 7),
        ]);
    }

    public function change(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // One of its own users may become owner; another customer's may not.
            'email' => ['required', 'email', 'max:255', UniqueAccountIdentity::email(null, (int) $school->getKey())],
        ]);

        abort_unless($school->isActive(), 409, __('The owner can be changed once the school is active.'));

        $this->owners->change($school, $data['name'], $data['email']);

        return ApiResponse::success(null, __('Owner changed.'));
    }
}
