<?php

declare(strict_types=1);

namespace App\Services\Recruitment;

use App\Models\Applicant;
use App\Models\JobPosition;
use App\Models\OfferLetter;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offer letters (HRM > Recruitment > Offer letter) and what each step does
 * to the applicant: making an offer moves them to Offer, a declined offer
 * marks them Withdrawn, and hiring (an accepted offer turned into a Staff
 * record) marks them Hired — and the job Filled once it has hired as many
 * people as it asked for.
 */
final class OfferLetterService
{
    /** Who an offer can still be made to. */
    private const OFFERABLE_STAGES = ['new', 'screening', 'shortlisted', 'interview', 'offer'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): OfferLetter
    {
        return DB::connection('tenant')->transaction(function () use ($data, $by) {
            $applicant = Applicant::query()->lockForUpdate()->findOrFail($data['applicant_id']);

            if (! in_array($applicant->stage, self::OFFERABLE_STAGES, true)) {
                throw ValidationException::withMessages(['applicant_id' => __('This applicant can no longer be made an offer.')]);
            }

            $offer = OfferLetter::query()->create([
                'job_position_id' => $applicant->job_position_id,
                ...$data,
                'created_by' => $by->getKey(),
            ]);

            $applicant->update(['stage' => 'offer']);

            return $offer;
        });
    }

    /**
     * A status change from the Offer letter tab, with its timestamps and its
     * effect on the applicant.
     */
    public function changeStatus(OfferLetter $offer, string $status): OfferLetter
    {
        if ($offer->hired_staff_id !== null) {
            throw ValidationException::withMessages(['status' => __('This offer has already been turned into a staff member.')]);
        }

        DB::connection('tenant')->transaction(function () use ($offer, $status) {
            $offer->update([
                'status' => $status,
                'sent_at' => $status === OfferLetter::STATUS_SENT ? ($offer->sent_at ?? now()) : $offer->sent_at,
                'responded_at' => in_array($status, [OfferLetter::STATUS_ACCEPTED, OfferLetter::STATUS_DECLINED], true) ? now() : $offer->responded_at,
            ]);

            if ($status === OfferLetter::STATUS_DECLINED) {
                $offer->applicant?->update(['stage' => 'withdrawn']);
            }
        });

        return $offer;
    }

    /** An accepted offer whose person was just added as staff. */
    public function hire(OfferLetter $offer, Staff $staff): OfferLetter
    {
        if ($offer->status !== OfferLetter::STATUS_ACCEPTED) {
            throw ValidationException::withMessages(['status' => __('Only an accepted offer can be hired.')]);
        }

        if ($offer->hired_staff_id !== null) {
            throw ValidationException::withMessages(['status' => __('This offer has already been turned into a staff member.')]);
        }

        DB::connection('tenant')->transaction(function () use ($offer, $staff) {
            $offer->update(['hired_staff_id' => $staff->id]);
            $offer->applicant?->update(['stage' => 'hired']);

            // Every seat filled: close the job (and with it, its Careers listing).
            $job = $offer->job_position_id !== null ? JobPosition::query()->lockForUpdate()->find($offer->job_position_id) : null;
            if ($job !== null && $job->status === JobPosition::STATUS_OPEN) {
                $hired = OfferLetter::query()->where('job_position_id', $job->id)->whereNotNull('hired_staff_id')->count();
                if ($hired >= $job->headcount) {
                    $job->update(['status' => JobPosition::STATUS_FILLED]);
                }
            }
        });

        return $offer;
    }
}
