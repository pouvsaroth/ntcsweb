<?php

declare(strict_types=1);

namespace App\Services\ProfessionalDevelopment;

use App\Models\TeacherQualification;
use App\Models\TrainingParticipant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Training certificates (docs/hrm/professional-development.md): issued to a
 * staff member for a training, with an optional scanned file (private
 * `local` disk), and kept in step with a "certificate" row in
 * Teachers > Qualification, so it shows on their qualifications too.
 */
final class TrainingCertificates
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{certificate_no?: ?string, issued_on: string, expires_on?: ?string}  $data
     */
    public function issue(TrainingParticipant $participant, array $data, ?UploadedFile $file): TrainingParticipant
    {
        return DB::connection('tenant')->transaction(function () use ($participant, $data, $file) {
            $number = trim((string) ($data['certificate_no'] ?? ''));
            $attributes = [
                'certificate_no' => $number !== '' ? $number : ($participant->certificate_no ?? $this->nextNumber((int) substr($data['issued_on'], 0, 4))),
                'certificate_issued_on' => $data['issued_on'],
                'certificate_expires_on' => $data['expires_on'] ?? null,
            ];

            if ($file !== null) {
                $this->deleteFile($participant);
                $folder = $this->context->getOrFail()->storagePath('training-certificates', (string) $participant->staff_id);
                $attributes += [
                    'certificate_file_path' => $file->storeAs($folder, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local'),
                    'certificate_file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'certificate_mime' => $file->getMimeType(),
                    'certificate_size' => (int) $file->getSize(),
                ];
            }

            $participant->update($attributes);
            $this->syncQualification($participant);

            return $participant;
        });
    }

    /** Take a certificate back: the file and its Qualification row go too. */
    public function revoke(TrainingParticipant $participant): void
    {
        DB::connection('tenant')->transaction(function () use ($participant) {
            $this->forget($participant);
            $participant->update([
                'certificate_no' => null,
                'certificate_issued_on' => null,
                'certificate_expires_on' => null,
                'certificate_file_path' => null,
                'certificate_file_name' => null,
                'certificate_mime' => null,
                'certificate_size' => null,
                'teacher_qualification_id' => null,
            ]);
        });
    }

    /** Before a participant (or their training) is deleted: drop the file and the Qualification row. */
    public function forget(TrainingParticipant $participant): void
    {
        $this->deleteFile($participant);
        if ($participant->teacher_qualification_id !== null) {
            TeacherQualification::query()->whereKey($participant->teacher_qualification_id)->delete();
        }
    }

    /** Write (or re-create, if it was deleted there) the Qualification row from the certificate and training. */
    public function syncQualification(TrainingParticipant $participant): void
    {
        if (! $participant->hasCertificate()) {
            return;
        }
        $training = $participant->training()->firstOrFail();

        $values = [
            'staff_id' => $participant->staff_id,
            'type' => 'certificate',
            'level' => null,
            'title' => Str::limit($training->title, 250, ''),
            'institution' => $training->trainer,
            'year' => (int) $participant->certificate_issued_on->format('Y'),
            'note' => Str::limit(__('Training certificate :no', ['no' => $participant->certificate_no]), 250, ''),
        ];

        $qualification = $participant->teacher_qualification_id !== null
            ? TeacherQualification::query()->find($participant->teacher_qualification_id)
            : null;
        if ($qualification !== null) {
            $qualification->update($values);
        } else {
            $qualification = TeacherQualification::query()->create($values);
            $participant->forceFill(['teacher_qualification_id' => $qualification->id])->save();
        }
    }

    /** CERT-<year>-0001, numbered on from the highest of that year. */
    private function nextNumber(int $year): string
    {
        $prefix = "CERT-{$year}-";
        $last = TrainingParticipant::query()->where('certificate_no', 'like', $prefix.'%')->pluck('certificate_no')
            ->map(fn (string $no) => (int) substr($no, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    private function deleteFile(TrainingParticipant $participant): void
    {
        if ($participant->certificate_file_path) {
            Storage::disk('local')->delete($participant->certificate_file_path);
        }
    }
}
