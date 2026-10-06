<?php

declare(strict_types=1);

namespace App\Services\Recruitment;

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;

/**
 * Scheduling interviews (HRM > Recruitment > Interview). Scheduling moves an
 * applicant who was still being screened to the Interview stage, and tells
 * each interviewer.
 */
final class InterviewService
{
    /** Stages before the interview — scheduling one moves the applicant on from these. */
    private const BEFORE_INTERVIEW = ['new', 'screening', 'shortlisted'];

    /**
     * The server keeps times in UTC; notification text is written once, on
     * the server, so it's put in the schools' own (Cambodian) time — the
     * screens themselves show each viewer's browser time.
     */
    private const NOTIFICATION_TIMEZONE = 'Asia/Phnom_Penh';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $interviewerIds
     */
    public function schedule(array $data, array $interviewerIds, User $by): Interview
    {
        $interview = DB::connection('tenant')->transaction(function () use ($data, $interviewerIds, $by) {
            $interview = Interview::query()->create([...$data, 'created_by' => $by->getKey()]);
            $this->syncInterviewers($interview, $interviewerIds);

            $applicant = $interview->applicant;
            if ($applicant !== null && in_array($applicant->stage, self::BEFORE_INTERVIEW, true)) {
                $applicant->update(['stage' => 'interview']);
            }

            return $interview;
        });

        $this->notifyInterviewers($interview, $interviewerIds);

        return $interview;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $interviewerIds  null = leave them as they are
     */
    public function update(Interview $interview, array $data, ?array $interviewerIds): Interview
    {
        $added = [];

        DB::connection('tenant')->transaction(function () use ($interview, $data, $interviewerIds, &$added) {
            $interview->update($data);

            if ($interviewerIds !== null) {
                $added = array_values(array_diff($interviewerIds, $interview->interviewerIds()));
                $this->syncInterviewers($interview, $interviewerIds);
            }
        });

        // Only someone newly added needs telling.
        $this->notifyInterviewers($interview, $added);

        return $interview;
    }

    /** @param  list<int>  $userIds */
    private function syncInterviewers(Interview $interview, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $interview->interviewerRows()->whereNotIn('user_id', $userIds)->delete();
        foreach ($userIds as $userId) {
            $interview->interviewerRows()->firstOrCreate(['user_id' => $userId]);
        }

        $interview->unsetRelation('interviewerRows');
    }

    /** @param  list<int>  $userIds */
    private function notifyInterviewers(Interview $interview, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        /** @var Applicant|null $applicant */
        $applicant = $interview->applicant;

        $this->notifications->notifyMany(
            User::query()->whereIn('id', $userIds)->get(),
            NotificationType::INTERVIEW_SCHEDULED,
            [
                'interview_id' => $interview->id,
                'applicant_name' => $applicant?->fullName(),
                'job_title' => $applicant?->jobPosition?->title,
                'date' => $interview->scheduled_at?->copy()->timezone(self::NOTIFICATION_TIMEZONE)->format('d-m-Y H:i'),
            ],
            link: '/admin/recruitment/interviews',
        );
    }
}
