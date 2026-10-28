<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StoreEnrollmentInquiryRequest;
use App\Http\Responses\ApiResponse;
use App\Models\EnrollmentInquiry;
use App\Models\Inquiry;
use App\Models\Program;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The public "Register" form's submission endpoint — captures a visitor's
 * interest, not a real enrollment. See EnrollmentInquiry's docblock. It also
 * lands in Front Office > Inquiry (source Website) so the front desk can
 * follow it up, and the people who handle inquiries are told.
 */
final class EnrollmentInquiryController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function store(StoreEnrollmentInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $inquiry = DB::connection('tenant')->transaction(function () use ($data) {
            $submission = EnrollmentInquiry::query()->create($data);
            $program = isset($data['program_id']) ? Program::query()->find($data['program_id']) : null;

            return Inquiry::query()->create([
                'inquiry_date' => now()->toDateString(),
                'name' => mb_substr($data['name'], 0, 150),
                'phone' => mb_substr($data['phone'], 0, 40),
                'email' => isset($data['email']) ? mb_substr($data['email'], 0, 150) : null,
                'interested_in' => $program?->title ? mb_substr($program->title, 0, 255) : null,
                'source' => 'website',
                'note' => $data['message'] ?? null,
                'enrollment_inquiry_id' => $submission->id,
            ]);
        });

        $this->notifications->notifyMany(
            $this->notifications->usersWithPermission(Permissions::INQUIRIES_UPDATE),
            NotificationType::WEBSITE_INQUIRY_RECEIVED,
            ['inquiry_id' => $inquiry->id, 'name' => $inquiry->name, 'interested_in' => $inquiry->interested_in ?? '—'],
            link: '/admin/front-office/inquiries',
        );

        return ApiResponse::created(null, 'Thank you — we will contact you soon.');
    }
}
