<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\EnrollmentTransferHistory;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Platform\Billing\StudentLimit;
use App\Services\Academic\EnrollmentService;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Students > Student record (docs/academics/student-record.md): one page of a
 * student's history — classes by academic year, status changes, class and
 * school transfers, and results (scores, attendance, promotion) by year.
 * Also changing a student's status, and putting students into a class with
 * no course package (Grade 1–12 class assignment).
 */
final class StudentRecordController extends Controller
{
    /** Statuses set here; "pending" and "inactive" stay with registration and the Student form. */
    private const STATUSES = [Student::STATUS_ACTIVE, Student::STATUS_TRANSFERRED, Student::STATUS_WITHDRAWN, Student::STATUS_GRADUATED];

    /** Enrollments still in their class. */
    private const OPEN = [Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_EXAM_READY, Enrollment::STATUS_SUSPENDED];

    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function show(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STUDENTS_VIEW), 403);
        $db = DB::connection('tenant');

        $enrollments = Enrollment::query()->with(['schoolClass.academicYear:id,name', 'schoolClass.gradeLevel:id,name', 'coursePackage:id,name'])
            ->where('student_id', $student->getKey())
            ->orderByDesc('enrolled_at')->orderByDesc('id')->get();
        $ids = $enrollments->modelKeys();

        // Latest score per subject, per enrollment (a retake replaces the earlier one).
        $scoreRows = $ids === [] ? collect() : $db->table('exam_scores as s')
            ->join('exam_applications as a', 'a.id', '=', 's.exam_application_id')
            ->leftJoin('books as b', 'b.id', '=', 'a.book_id')
            ->whereIn('a.enrollment_id', $ids)->whereNull('a.deleted_at')
            ->orderBy('s.id')
            ->get(['a.enrollment_id', 'a.book_id', 'b.title', 's.score', 'a.exam_date']);
        $scores = [];
        foreach ($scoreRows as $r) {
            $scores[(int) $r->enrollment_id][(int) ($r->book_id ?? 0)] = ['subject' => $r->title, 'score' => (float) $r->score, 'exam_date' => $r->exam_date];
        }

        $attendance = $ids === [] ? collect() : $db->table('attendance_records')->whereIn('enrollment_id', $ids)
            ->selectRaw('enrollment_id, count(*) as total, sum(case when status in (?, ?, ?) then 1 else 0 end) as attended, sum(case when status = ? then 1 else 0 end) as absent', [
                AttendanceStatus::PRESENT, AttendanceStatus::LATE, AttendanceStatus::EXCUSED, AttendanceStatus::ABSENT,
            ])->groupBy('enrollment_id')->get()->keyBy('enrollment_id');

        $promotions = StudentPromotion::query()->with('toClass:id,name')->whereIn('from_enrollment_id', $ids)->get()->keyBy('from_enrollment_id');

        $rows = $enrollments->map(function (Enrollment $e) use ($scores, $attendance, $promotions) {
            $subjects = array_values($scores[$e->id] ?? []);
            $att = $attendance->get($e->id);
            $promo = $promotions->get($e->id);

            return [
                'enrollment_id' => $e->id,
                'enrollments_code' => $e->enrollments_code,
                'class_id' => $e->class_id,
                'class' => $e->schoolClass?->name,
                'grade_level' => $e->schoolClass?->gradeLevel?->name,
                'academic_year' => $e->schoolClass?->academicYear?->name,
                'course' => $e->coursePackage?->name,
                'status' => $e->status,
                'enrolled_at' => $e->enrolled_at ? Carbon::parse($e->enrolled_at)->toDateString() : null,
                'subjects' => $subjects,
                'average' => $subjects === [] ? null : round(array_sum(array_column($subjects, 'score')) / count($subjects), 2),
                'attendance_rate' => $att && (int) $att->total > 0 ? round(((int) $att->attended / (int) $att->total) * 100, 1) : null,
                'absent_days' => $att ? (int) $att->absent : 0,
                'promotion' => $promo ? ['result' => $promo->result, 'to_class' => $promo->toClass?->name, 'at' => $promo->created_at?->toDateString()] : null,
            ];
        })->values();

        // Class transfers (Enrollments > Transfer) and school transfers (status changes).
        $classTransfers = $ids === [] ? collect() : EnrollmentTransferHistory::query()->with(['fromClass:id,name', 'toClass:id,name'])
            ->whereIn('enrollment_id', $ids)->whereColumn('from_class_id', '!=', 'to_class_id')
            ->orderByDesc('created_at')->get()
            ->map(fn (EnrollmentTransferHistory $h) => [
                'kind' => 'class', 'date' => $h->created_at?->toDateString(),
                'from' => $h->fromClass?->name, 'to' => $h->toClass?->name, 'reason' => null,
            ]);
        $statusHistory = $db->table('student_status_histories')->where('student_id', $student->getKey())
            ->orderByDesc('effective_date')->orderByDesc('id')->get();
        $schoolTransfers = $statusHistory->where('to_status', Student::STATUS_TRANSFERRED)->map(fn ($h) => [
            'kind' => 'school', 'date' => $h->effective_date, 'from' => null, 'to' => $h->to_school, 'reason' => $h->reason,
        ]);

        $student->load('guardians');

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'student_code' => $student->student_code,
                'name' => trim("{$student->first_name} {$student->last_name}"),
                'english_name' => $student->english_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth ? Carbon::parse($student->date_of_birth)->toDateString() : null,
                'phone' => $student->phone,
                'email' => $student->email,
                'status' => $student->status,
                'enrollment_date' => $student->enrollment_date ? Carbon::parse($student->enrollment_date)->toDateString() : null,
                'guardians' => $student->guardians->map(fn ($g) => [
                    'name' => $g->guardian_name, 'type' => $g->guardian_type, 'phone' => $g->phone, 'email' => $g->email, 'address' => $g->address,
                ])->values(),
            ],
            'classes' => $rows,
            'status_history' => $statusHistory->map(fn ($h) => [
                'from' => $h->from_status, 'to' => $h->to_status, 'date' => $h->effective_date, 'reason' => $h->reason, 'to_school' => $h->to_school,
            ])->values(),
            'transfers' => $classTransfers->concat($schoolTransfers)->sortByDesc('date')->values(),
        ]);
    }

    /**
     * Studying, transferred out (to another school), dropped out or
     * graduated — with the date and reason, kept in the status history.
     * Leaving the school can also end the student's open classes.
     */
    public function changeStatus(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STUDENTS_UPDATE), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,transferred,withdrawn'],
            'to_school' => ['nullable', 'string', 'max:255', 'required_if:status,transferred'],
            'end_classes' => ['sometimes', 'boolean'],
        ]);
        if ($data['status'] === $student->status) {
            throw ValidationException::withMessages(['status' => __('The student already has this status.')]);
        }

        $ended = 0;
        DB::connection('tenant')->transaction(function () use ($student, $data, $request, &$ended) {
            DB::connection('tenant')->table('student_status_histories')->insert([
                'student_id' => $student->getKey(),
                'from_status' => $student->status,
                'to_status' => $data['status'],
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? null,
                'to_school' => $data['status'] === Student::STATUS_TRANSFERRED ? ($data['to_school'] ?? null) : null,
                'changed_by' => $request->user()->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $student->update(['status' => $data['status']]);

            if ($data['status'] !== Student::STATUS_ACTIVE && ($data['end_classes'] ?? true)) {
                // Graduated: completed. Transferred / dropped out: stopped, with the reason.
                $to = $data['status'] === Student::STATUS_GRADUATED ? Enrollment::STATUS_COMPLETED : Enrollment::STATUS_STOPPED;
                $reason = $data['reason'] ?? __('Graduated');
                foreach (Enrollment::query()->where('student_id', $student->getKey())->whereIn('status', self::OPEN)->get() as $e) {
                    $this->enrollments->changeStatus($e, $to, $reason, $data['effective_date'], $request->user());
                    $ended++;
                }
            }
        });

        return ApiResponse::success(['status' => $student->status, 'ended_classes' => $ended]);
    }

    /**
     * Class assignment: put students into a class, with no course package and
     * no invoice (a Grade 1–12 class). Students already in it are skipped.
     */
    public function assignToClass(Request $request, SchoolClass $class): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::ENROLLMENTS_CREATE), 403);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['integer', Rule::exists('tenant.students', 'id')->whereNull('deleted_at')],
        ]);

        $added = 0;
        $skipped = 0;
        DB::connection('tenant')->transaction(function () use ($data, $class, &$added, &$skipped) {
            foreach (array_unique($data['student_ids']) as $studentId) {
                $already = Enrollment::query()->where('student_id', $studentId)->where('class_id', $class->getKey())
                    ->whereIn('status', [...self::OPEN, Enrollment::STATUS_COMPLETED])->exists();
                if ($already) {
                    $skipped++;

                    continue;
                }
                app(StudentLimit::class)->assertRoomFor((int) $studentId);
                $this->enrollments->enrollForPromotion((int) $studentId, $class);
                $added++;
            }
        });

        return ApiResponse::success(['added' => $added, 'skipped' => $skipped]);
    }
}
