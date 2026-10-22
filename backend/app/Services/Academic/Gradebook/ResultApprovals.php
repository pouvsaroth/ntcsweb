<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AssessmentScheme;
use App\Models\GradeCorrection;
use App\Models\GradeEntry;
use App\Models\GradeEntryHistory;
use App\Models\ResultSheet;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Result Approval and Grade Correction (docs/academics/gradebook.md, 7c),
 * both decided through eApprovals: an Approval Flow when the school set one
 * up for the item, else whoever holds `gradebook.approve`.
 *
 * - A sheet is submitted (draft | returned → pending) once nothing is missing;
 *   approved → locked; returned → editable again, with the reason shown.
 * - A correction asks to change one cell of an approved sheet, with a reason;
 *   approved → the entry changes, a history row is written, the sheet is
 *   recalculated.
 */
final class ResultApprovals
{
    public function __construct(
        private readonly GradebookService $gradebook,
        private readonly Calculator $calculator,
        private readonly ApprovalFlow $flow,
        private readonly NotificationService $notifications,
    ) {}

    // --- Result sheets ------------------------------------------------------------

    public function submit(ResultSheet $sheet, User $by): ResultSheet
    {
        if (! $sheet->isEditable()) {
            throw ValidationException::withMessages(['status' => __('These results were already submitted.')]);
        }

        $sheet->loadMissing(['schoolClass', 'book', 'term']);
        $row = collect($this->gradebook->validation(collect([$sheet->schoolClass]), $sheet->term))
            ->first(fn ($r) => $r['subject']['id'] === (int) $sheet->book_id);
        $blocking = array_values(array_intersect($row['issues'] ?? ['missing'], ['no_scheme', 'weights_not_100', 'setup_incomplete', 'missing', 'over_max', 'no_students']));
        if ($blocking !== []) {
            throw ValidationException::withMessages(['sheet' => __('Not ready to submit: :issues. See Grade Validation.', ['issues' => implode(', ', $blocking)])]);
        }

        $sheet->forceFill([
            'status' => ResultSheet::STATUS_PENDING, 'requested_by' => $by->getKey(), 'submitted_by' => $by->getKey(), 'submitted_at' => now(),
            'decided_by' => null, 'decided_at' => null, 'decision_note' => null,
        ])->save();

        $this->notifications->notifyMany(
            $this->flow->submitRecipients($sheet, fn () => $this->notifications->usersWithPermission(Permissions::GRADEBOOK_APPROVE)),
            NotificationType::RESULT_SHEET_SUBMITTED, $this->sheetData($sheet, $by), link: '/admin/approvals/queue',
        );

        return $sheet;
    }

    public function approveSheet(ResultSheet $sheet, User $by): ResultSheet
    {
        $this->flow->authorizeDecision($sheet, $by, 'approve');

        return $this->flow->approve(
            $sheet,
            $by,
            function (ResultSheet $doc) use ($by) {
                $doc = DB::connection('tenant')->transaction(function () use ($doc, $by) {
                    $locked = ResultSheet::query()->whereKey($doc->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertPending($locked->status);
                    $locked->forceFill(['status' => ResultSheet::STATUS_APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();

                    return $locked;
                });
                $this->tellRequester($doc, NotificationType::RESULT_SHEET_APPROVED, $this->sheetData($doc, $by));

                return $doc;
            },
            fn (ResultSheet $doc, Collection $next) => $this->notifications->notifyMany($next, NotificationType::RESULT_SHEET_SUBMITTED, $this->sheetData($doc, null), link: '/admin/approvals/queue'),
        );
    }

    /** "Reject" in eApprovals terms: the sheet goes back to the teacher, editable, with the reason. */
    public function returnSheet(ResultSheet $sheet, string $reason, User $by): ResultSheet
    {
        $this->flow->authorizeDecision($sheet, $by, 'reject');

        $sheet = DB::connection('tenant')->transaction(function () use ($sheet, $reason, $by) {
            $locked = ResultSheet::query()->whereKey($sheet->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($locked->status);
            $locked->forceFill(['status' => ResultSheet::STATUS_RETURNED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decision_note' => $reason])->save();
            // A new submission starts the flow again.
            DB::connection('tenant')->table('approval_step_approvals')->where('approvable_type', 'result_sheet')->where('approvable_id', $locked->id)->delete();

            return $locked;
        });
        $this->tellRequester($sheet, NotificationType::RESULT_SHEET_RETURNED, $this->sheetData($sheet, $by) + ['reason' => $reason]);

        return $sheet;
    }

    // --- Grade corrections ----------------------------------------------------------

    /**
     * Ask to change one cell of an approved sheet.
     *
     * @param  array{student_id: int, component_id?: int|null, occurrence?: int|null, criterion_id?: int|null, score?: mixed, absent?: bool, level_id?: int|null, reason: string}  $data
     */
    public function requestCorrection(ResultSheet $sheet, array $data, User $by): GradeCorrection
    {
        if ($sheet->status !== ResultSheet::STATUS_APPROVED) {
            throw ValidationException::withMessages(['sheet' => __('Only approved results are corrected this way. Results not yet approved can still be changed in Grade Entry (or returned by the approver).')]);
        }
        $sheet->loadMissing('schoolClass');
        $scheme = AssessmentScheme::query()->with(['components', 'criteria', 'levels'])->find($sheet->assessment_scheme_id);
        if ($scheme === null) {
            throw ValidationException::withMessages(['sheet' => __('This sheet has no assessment setup.')]);
        }
        if (! $this->gradebook->roster($sheet->schoolClass)->contains('id', (int) $data['student_id'])) {
            throw ValidationException::withMessages(['student_id' => __('This student is not in the class.')]);
        }

        $keys = ['result_sheet_id' => $sheet->id, 'student_id' => (int) $data['student_id']];
        if ($scheme->mode === AssessmentScheme::MODE_SCORE) {
            $component = $scheme->components->firstWhere('id', (int) ($data['component_id'] ?? 0));
            $occ = (int) ($data['occurrence'] ?? 1);
            if ($component === null || $occ < 1 || $occ > $component->occurrences) {
                throw ValidationException::withMessages(['component_id' => __('This assessment is not in the setup.')]);
            }
            $absent = (bool) ($data['absent'] ?? false);
            $score = $absent || ($data['score'] ?? null) === null || $data['score'] === '' ? null : $data['score'];
            if ($score !== null && (! is_numeric($score) || (float) $score < 0 || (float) $score > (float) $component->max_score)) {
                throw ValidationException::withMessages(['score' => __('Enter a score from 0 to :max.', ['max' => (float) $component->max_score])]);
            }
            $keys += ['assessment_component_id' => $component->id, 'occurrence' => $occ];
            $new = ['new_score' => $score, 'new_absent' => $absent, 'new_level_id' => null];
        } else {
            $criterion = $scheme->criteria->firstWhere('id', (int) ($data['criterion_id'] ?? 0));
            $levelId = $data['level_id'] ?? null;
            if ($criterion === null) {
                throw ValidationException::withMessages(['criterion_id' => __('This criterion is not in the setup.')]);
            }
            if ($levelId !== null && ! $scheme->levels->contains('id', (int) $levelId)) {
                throw ValidationException::withMessages(['level_id' => __('This level is not in the setup.')]);
            }
            $keys += ['competency_criterion_id' => $criterion->id];
            $new = ['new_score' => null, 'new_absent' => false, 'new_level_id' => $levelId];
        }

        $entry = GradeEntry::query()->where($keys)->first();
        $old = ['old_score' => $entry?->score, 'old_absent' => (bool) $entry?->absent, 'old_level_id' => $entry?->competency_level_id];
        if ((string) $old['old_score'] === (string) ($new['new_score'] === null ? null : number_format((float) $new['new_score'], 2, '.', ''))
            && $old['old_absent'] === $new['new_absent'] && (int) $old['old_level_id'] === (int) $new['new_level_id']) {
            throw ValidationException::withMessages(['score' => __('That is the score it already has.')]);
        }
        if (GradeCorrection::query()->where($keys)->where('status', GradeCorrection::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages(['score' => __('A correction of this score is already waiting for a decision.')]);
        }

        $correction = GradeCorrection::query()->create($keys + $old + $new + [
            'occurrence' => $keys['occurrence'] ?? 1,
            'reason' => trim($data['reason']),
            'status' => GradeCorrection::STATUS_PENDING,
            'requested_by' => $by->getKey(),
        ]);

        $this->notifications->notifyMany(
            $this->flow->submitRecipients($correction, fn () => $this->notifications->usersWithPermission(Permissions::GRADEBOOK_APPROVE)),
            NotificationType::GRADE_CORRECTION_SUBMITTED, $this->correctionData($correction, $by), link: '/admin/approvals/queue',
        );

        return $correction;
    }

    public function approveCorrection(GradeCorrection $correction, User $by): GradeCorrection
    {
        $this->flow->authorizeDecision($correction, $by, 'approve');

        return $this->flow->approve(
            $correction,
            $by,
            function (GradeCorrection $doc) use ($by) {
                $doc = DB::connection('tenant')->transaction(function () use ($doc, $by) {
                    $locked = GradeCorrection::query()->whereKey($doc->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertPending($locked->status);
                    $this->apply($locked, $by);
                    $locked->forceFill(['status' => GradeCorrection::STATUS_APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();

                    return $locked;
                });
                $this->tellRequester($doc, NotificationType::GRADE_CORRECTION_APPROVED, $this->correctionData($doc, $by));

                return $doc;
            },
            fn (GradeCorrection $doc, Collection $next) => $this->notifications->notifyMany($next, NotificationType::GRADE_CORRECTION_SUBMITTED, $this->correctionData($doc, null), link: '/admin/approvals/queue'),
        );
    }

    public function rejectCorrection(GradeCorrection $correction, string $reason, User $by): GradeCorrection
    {
        $this->flow->authorizeDecision($correction, $by, 'reject');

        $correction = DB::connection('tenant')->transaction(function () use ($correction, $reason, $by) {
            $locked = GradeCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($locked->status);
            $locked->forceFill(['status' => GradeCorrection::STATUS_REJECTED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decision_reason' => $reason])->save();

            return $locked;
        });
        $this->tellRequester($correction, NotificationType::GRADE_CORRECTION_REJECTED, $this->correctionData($correction, $by) + ['reason' => $reason]);

        return $correction;
    }

    /** The approved change: the entry, a history row, the sheet's results. */
    private function apply(GradeCorrection $c, User $by): void
    {
        $keys = array_filter([
            'result_sheet_id' => $c->result_sheet_id, 'student_id' => $c->student_id,
            'assessment_component_id' => $c->assessment_component_id, 'competency_criterion_id' => $c->competency_criterion_id,
        ], fn ($v) => $v !== null);
        if ($c->assessment_component_id !== null) {
            $keys['occurrence'] = $c->occurrence;
        }

        $entry = GradeEntry::query()->where($keys)->first();
        $empty = $c->new_score === null && ! $c->new_absent && $c->new_level_id === null;
        if ($empty) {
            $entry?->delete();
        } else {
            GradeEntry::query()->updateOrCreate($keys, [
                'score' => $c->new_score, 'absent' => $c->new_absent, 'competency_level_id' => $c->new_level_id, 'updated_by' => $by->getKey(),
            ]);
        }

        GradeEntryHistory::query()->create([
            'result_sheet_id' => $c->result_sheet_id, 'student_id' => $c->student_id,
            'assessment_component_id' => $c->assessment_component_id, 'occurrence' => $c->occurrence, 'competency_criterion_id' => $c->competency_criterion_id,
            'old_score' => $entry?->getOriginal('score'), 'old_absent' => (bool) $entry?->getOriginal('absent'), 'old_level_id' => $entry?->getOriginal('competency_level_id'),
            'new_score' => $c->new_score, 'new_absent' => $c->new_absent, 'new_level_id' => $c->new_level_id,
            'reason' => $c->reason, 'grade_correction_id' => $c->id, 'changed_by' => $by->getKey(), 'changed_at' => now(),
        ]);

        $sheet = ResultSheet::query()->with('schoolClass')->findOrFail($c->result_sheet_id);
        $this->calculator->recalculate($sheet, $this->gradebook->roster($sheet->schoolClass)->pluck('id'));
    }

    private function assertPending(string $status): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('This has already been decided.')]);
        }
    }

    private function tellRequester(ResultSheet|GradeCorrection $doc, string $type, array $data): void
    {
        $requester = $doc->requested_by ? User::query()->find($doc->requested_by) : null;
        if ($requester !== null) {
            $this->notifications->notify($requester, $type, $data, link: '/admin/examination/'.($doc instanceof ResultSheet ? 'approval' : 'corrections'));
        }
    }

    /** @return array<string, mixed> */
    private function sheetData(ResultSheet $sheet, ?User $by): array
    {
        $sheet->loadMissing(['schoolClass:id,name', 'book:id,title', 'term:id,name']);

        return [
            'result_sheet_id' => $sheet->id, 'class_name' => $sheet->schoolClass?->name, 'subject' => $sheet->book?->title,
            'term' => $sheet->term?->name, 'by_name' => $by?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function correctionData(GradeCorrection $c, ?User $by): array
    {
        $c->loadMissing(['sheet.schoolClass:id,name', 'sheet.book:id,title', 'student:id,first_name,last_name']);

        return [
            'grade_correction_id' => $c->id, 'student_name' => $c->student?->fullName(), 'class_name' => $c->sheet?->schoolClass?->name,
            'subject' => $c->sheet?->book?->title, 'by_name' => $by?->name,
        ];
    }
}
