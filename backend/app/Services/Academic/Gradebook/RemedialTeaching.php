<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AcademicTerm;
use App\Models\PromotionRule;
use App\Models\RemedialSession;
use App\Models\RemedialSessionStudent;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\SubjectResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Examination > Remedial Teaching (docs/academics/gradebook.md, 7e).
 *
 * Students below the pass mark: in a class + term, every subject result
 * (draft or approved — teachers act early) whose percent is under the grade's
 * pass mark. Sessions: extra teaching for one subject, the students taken,
 * whether they came and the outcome. Who may see / record follows the
 * gradebook (GradebookAccess): open the class to see, enter the subject to
 * record.
 */
final class RemedialTeaching
{
    private const WITH = ['book:id,title', 'teacher:id,first_name,last_name', 'students.student:id,student_code,first_name,last_name'];

    public function __construct(
        private readonly GradebookAccess $access,
        private readonly GradebookService $gradebook,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $user, SchoolClass $class, AcademicTerm $term, ?int $bookId): array
    {
        $subjects = $this->access->subjects($class)->when($bookId, fn ($c, $id) => $c->where('id', $id))->values();
        $passMark = (float) (PromotionRule::for($class->grade_level_id)?->pass_mark ?? 50);
        $roster = $this->gradebook->roster($class)->keyBy('id');

        $sheets = ResultSheet::query()->where('class_id', $class->getKey())->where('academic_term_id', $term->getKey())
            ->whereIn('book_id', $subjects->modelKeys() ?: [0])->get()->keyBy('id');
        $results = SubjectResult::query()->whereIn('result_sheet_id', $sheets->keys()->all() ?: [0])
            ->whereNotNull('percent')->where('percent', '<', $passMark)
            ->whereIn('student_id', $roster->keys()->all() ?: [0])
            ->get();

        $sessions = RemedialSession::query()->with(self::WITH)
            ->where('class_id', $class->getKey())->where('academic_term_id', $term->getKey())
            ->when($bookId, fn ($q, $id) => $q->where('book_id', $id))
            ->orderByDesc('session_date')->orderByDesc('id')->get();

        // Per subject + student: how many sessions they were taken to, and the latest outcome.
        $taken = [];
        foreach ($sessions->sortBy(fn ($s) => $s->session_date?->toDateString().'|'.str_pad((string) $s->id, 10, '0', STR_PAD_LEFT)) as $s) {
            foreach ($s->students as $row) {
                $key = $s->book_id.'-'.$row->student_id;
                $taken[$key]['count'] = ($taken[$key]['count'] ?? 0) + 1;
                if ($row->outcome !== null) {
                    $taken[$key]['outcome'] = $row->outcome;
                }
            }
        }

        $below = $results->map(function (SubjectResult $r) use ($sheets, $subjects, $roster, $taken) {
            $sheet = $sheets->get($r->result_sheet_id);
            $student = $roster->get($r->student_id);
            $key = $sheet->book_id.'-'.$r->student_id;

            return [
                'student' => ['id' => $student->id, 'code' => $student->student_code, 'name' => $student->fullName()],
                'subject' => ['id' => $sheet->book_id, 'title' => $subjects->firstWhere('id', $sheet->book_id)?->title],
                'percent' => (float) $r->percent,
                'score' => $r->score === null ? null : (float) $r->score,
                'band' => $r->band,
                'complete' => (bool) $r->complete,
                'sheet_status' => $sheet->status,
                'sessions' => $taken[$key]['count'] ?? 0,
                'last_outcome' => $taken[$key]['outcome'] ?? null,
            ];
        })->sortBy([['subject.title', 'asc'], ['percent', 'asc']])->values();

        return [
            'class' => ['id' => $class->id, 'name' => $class->name],
            'term' => ['id' => $term->id, 'name' => $term->name],
            'pass_mark' => $passMark,
            'subjects' => $subjects->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'can_record' => $this->access->canEnter($user, $class, $b->id)])->values(),
            'teachers' => $this->teachers($class),
            'below' => $below,
            'sessions' => $sessions->map(fn (RemedialSession $s) => $this->present($user, $s, $class))->values(),
        ];
    }

    /**
     * Create (no $session) or change a session.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $user, SchoolClass $class, AcademicTerm $term, array $data, ?RemedialSession $session = null): RemedialSession
    {
        $this->gradebook->ensureTermFitsClass($class, $term);
        $bookId = (int) $data['book_id'];
        if ($this->access->subjects($class)->where('id', $bookId)->isEmpty()) {
            throw ValidationException::withMessages(['book_id' => __('This subject is not taught in this class.')]);
        }
        abort_unless($this->access->canEnter($user, $class, $bookId), 403);

        $rows = collect($data['students'] ?? [])->keyBy(fn ($r) => (int) $r['student_id']);
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['students' => __('Choose at least one student.')]);
        }
        if ($rows->keys()->diff($this->gradebook->roster($class)->modelKeys())->isNotEmpty()) {
            throw ValidationException::withMessages(['students' => __('A student is not in this class.')]);
        }

        $staffId = array_key_exists('staff_id', $data) ? ($data['staff_id'] ? (int) $data['staff_id'] : null) : $this->access->staffId($user);
        if ($staffId !== null && $this->teachers($class)->where('id', $staffId)->isEmpty() && $staffId !== $this->access->staffId($user)) {
            throw ValidationException::withMessages(['staff_id' => __('This teacher does not teach this class.')]);
        }

        return DB::connection('tenant')->transaction(function () use ($user, $class, $term, $data, $session, $bookId, $rows, $staffId) {
            $session ??= new RemedialSession(['created_by' => $user->getKey()]);
            $subjectChanged = $session->exists && (int) $session->book_id !== $bookId;
            $session->fill([
                'class_id' => $class->getKey(),
                'book_id' => $bookId,
                'academic_term_id' => $term->getKey(),
                'staff_id' => $staffId,
                'session_date' => $data['session_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'topic' => $data['topic'],
                'notes' => $data['notes'] ?? null,
            ])->save();

            $percents = $this->percents($class, $term, $bookId, $rows->keys()->all());
            $existing = $session->students()->get()->keyBy('student_id');
            $session->students()->whereNotIn('student_id', $rows->keys()->all())->delete();
            foreach ($rows as $studentId => $row) {
                $line = $existing->get($studentId) ?? new RemedialSessionStudent(['remedial_session_id' => $session->getKey(), 'student_id' => $studentId]);
                // The percent when the student was taken (kept on later edits, unless the subject changes).
                if (! $line->exists || $subjectChanged) {
                    $line->percent_before = $percents[$studentId] ?? null;
                }
                $line->fill([
                    'attended' => $row['attended'] ?? null,
                    'outcome' => $row['outcome'] ?? null,
                    'remark' => $row['remark'] ?? null,
                ])->save();
            }

            return $session->load(self::WITH);
        });
    }

    public function delete(User $user, RemedialSession $session): void
    {
        abort_unless($this->access->canEnter($user, $session->schoolClass()->firstOrFail(), (int) $session->book_id), 403);
        $session->delete();
    }

    /** @return array<string, mixed> */
    public function present(User $user, RemedialSession $s, SchoolClass $class): array
    {
        return [
            'id' => $s->id,
            'subject' => ['id' => $s->book_id, 'title' => $s->book?->title],
            'teacher' => $s->teacher ? ['id' => $s->teacher->id, 'name' => $s->teacher->fullName()] : null,
            'session_date' => $s->session_date?->toDateString(),
            'start_time' => $s->start_time ? substr((string) $s->start_time, 0, 5) : null,
            'end_time' => $s->end_time ? substr((string) $s->end_time, 0, 5) : null,
            'topic' => $s->topic,
            'notes' => $s->notes,
            'students' => $s->students->map(fn (RemedialSessionStudent $r) => [
                'student_id' => $r->student_id,
                'code' => $r->student?->student_code,
                'name' => $r->student?->fullName(),
                'percent_before' => $r->percent_before === null ? null : (float) $r->percent_before,
                'attended' => $r->attended,
                'outcome' => $r->outcome,
                'remark' => $r->remark,
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'can_edit' => $this->access->canEnter($user, $class, (int) $s->book_id),
        ];
    }

    /**
     * The staff who teach the class: homeroom, teacher list, subject assignments.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function teachers(SchoolClass $class): Collection
    {
        $db = DB::connection('tenant');
        $ids = collect([$class->homeroom_staff_id])
            ->merge($db->table('class_teachers')->where('class_id', $class->getKey())->pluck('staff_id'))
            ->merge($db->table('teacher_assignments')->where('class_id', $class->getKey())->pluck('staff_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique();

        return Staff::query()->whereIn('id', $ids->all() ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn (Staff $s) => ['id' => (int) $s->id, 'name' => $s->fullName()])->values();
    }

    /**
     * Each student's current percent in the subject this term.
     *
     * @param  list<int>  $studentIds
     * @return array<int, float>
     */
    private function percents(SchoolClass $class, AcademicTerm $term, int $bookId, array $studentIds): array
    {
        $sheet = $this->gradebook->sheetFor($class, $bookId, $term);
        if ($sheet === null) {
            return [];
        }

        return SubjectResult::query()->where('result_sheet_id', $sheet->getKey())->whereIn('student_id', $studentIds ?: [0])->whereNotNull('percent')
            ->pluck('percent', 'student_id')->map(fn ($p) => (float) $p)->all();
    }
}
