<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A staff member's bank KHQR image, shown on their payslip so the accountant
 * can scan it to pay. Only the staff member themselves (the login linked to
 * the record) may add, replace or remove it — so nobody else can swap in a
 * different account. Others only see it.
 */
final class StaffBankQrController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeOwner($request, $staff);

        $request->validate(['bank_qr' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120']]);

        $path = $request->file('bank_qr')->store($this->context->getOrFail()->storagePath('staff'), 'public');
        abort_if($path === false, 500, 'Failed to store the uploaded QR.');

        $this->replace($staff, $path);

        return ApiResponse::success(['bank_qr_url' => $staff->bankQrUrl()]);
    }

    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeOwner($request, $staff);

        $this->replace($staff, null);

        return ApiResponse::success(['bank_qr_url' => null]);
    }

    private function authorizeOwner(Request $request, Staff $staff): void
    {
        abort_unless($staff->user_id !== null && $staff->user_id === $request->user()?->id, 403);
    }

    private function replace(Staff $staff, ?string $path): void
    {
        $previous = $staff->bank_qr_path;
        $staff->forceFill(['bank_qr_path' => $path])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }
    }
}
