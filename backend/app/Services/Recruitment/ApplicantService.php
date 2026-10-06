<?php

declare(strict_types=1);

namespace App\Services\Recruitment;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Applicants and their files (HRM > Recruitment) — shared by the public
 * Careers page's Apply form and HR adding someone by hand.
 */
final class ApplicantService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $cv, ?User $addedBy): Applicant
    {
        $this->refuseRepeat($data);

        $storedPaths = [];

        try {
            $applicant = DB::connection('tenant')->transaction(function () use ($data, $cv, $addedBy, &$storedPaths) {
                $applicant = Applicant::query()->create($data);

                if ($cv !== null) {
                    $storedPaths[] = $this->attach($applicant, $cv, ApplicantDocument::TYPE_CV, $addedBy)->file_path;
                }

                return $applicant;
            });
        } catch (\Throwable $e) {
            // Nothing committed — don't leave the uploaded file behind either.
            Storage::disk('local')->delete($storedPaths);

            throw $e;
        }

        // Someone applied on the website: tell whoever handles recruitment.
        if ($addedBy === null) {
            $this->notifications->notifyMany(
                $this->notifications->usersWithPermission(Permissions::RECRUITMENT_VIEW),
                NotificationType::JOB_APPLICATION_RECEIVED,
                [
                    'applicant_id' => $applicant->id,
                    'applicant_name' => $applicant->fullName(),
                    'job_title' => $applicant->jobPosition?->title,
                ],
                link: '/admin/recruitment/applicants',
            );
        }

        return $applicant;
    }

    public function attach(Applicant $applicant, UploadedFile $file, string $type, ?User $uploadedBy): ApplicantDocument
    {
        $path = $file->store($this->context->getOrFail()->storagePath('recruitment', 'applicants', (string) $applicant->id), 'local');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded file.');
        }

        return ApplicantDocument::query()->create([
            'applicant_id' => $applicant->id,
            'type' => $type,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $uploadedBy?->getKey(),
        ]);
    }

    /**
     * The same phone applying to the same job twice is the same person
     * clicking Apply again — refused rather than duplicated.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseRepeat(array $data): void
    {
        if (empty($data['job_position_id'])) {
            return;
        }

        $phone = Applicant::normalizePhone((string) $data['phone']);
        $exists = Applicant::query()
            ->where('job_position_id', $data['job_position_id'])
            ->get(['phone'])
            ->contains(fn (Applicant $applicant) => Applicant::normalizePhone($applicant->phone) === $phone);

        if ($exists) {
            throw ValidationException::withMessages(['phone' => __('Someone with this phone number has already applied for this job.')]);
        }
    }
}
