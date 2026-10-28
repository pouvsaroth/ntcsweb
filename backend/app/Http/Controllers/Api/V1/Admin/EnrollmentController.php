<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CancelEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\ChangeEnrollmentStatusRequest;
use App\Http\Requests\Api\V1\Admin\ChangeEnrollmentTableRequest;
use App\Http\Requests\Api\V1\Admin\TransferEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\EnrollmentStatusHistoryResource;
use App\Http\Resources\EnrollmentTransferHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\EnrollmentResponsibleStaff;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Platform\Billing\StudentLimit;
use App\Services\Academic\EnrollmentService;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

final class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    private const WITH = ['student', 'schoolClass', 'table', 'coursePackage', 'academicProgram', 'invoiceItems.invoice'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $enrollments = ApiQuery::for(
            Enrollment::query()->with([...self::WITH, 'latestStatusChange', 'currentResponsibleStaff.staff'])
                // Enrollment by academic year: the year of the class (School setup).
                ->when($request->filled('filter.academic_year_id'), fn ($q) => $q->whereHas('schoolClass', fn ($c) => $c->where('academic_year_id', $request->integer('filter.academic_year_id')))),
            $request,
        )
            ->filterable(['status', 'student_id', 'class_id', 'course_package_id', 'academic_program_id'])
            ->sortable(['enrolled_at', 'created_at', 'class_id', 'status', 'table_id'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(EnrollmentResource::collection($enrollments));
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        // Back into a counted status: the plan's student limit applies (back office billing, 5d).
        $status = $request->validated('status');
        if (in_array($status, StudentLimit::COUNTED, true) && ! in_array($enrollment->status, StudentLimit::COUNTED, true)) {
            app(StudentLimit::class)->assertRoomFor((int) $enrollment->student_id, (int) $enrollment->getKey());
        }

        $from = (string) $enrollment->status;
        DB::connection('tenant')->transaction(function () use ($request, $enrollment, $from) {
            $enrollment->update($request->validated());
            // A status changed here is kept in the status history too.
            $this->enrollments->recordStatus($enrollment, $from, (string) $enrollment->status, null, null, $request->user());
        });

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('delete', $enrollment);

        // Its invoice (and payment) would be left pointing at nothing, and the
        // student's payment type with it. A real enrollment is ended with
        // Cancel or Change status instead; only one entered by mistake, with
        // no invoice yet, can be deleted.
        if ($enrollment->invoiceItems()->exists()) {
            throw ValidationException::withMessages([
                'enrollment' => 'This enrollment has an invoice, so it cannot be deleted. Use Cancel or Change status instead.',
            ]);
        }

        $enrollment->delete();

        return ApiResponse::noContent();
    }

    public function cancel(CancelEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->cancel($enrollment, $request->validated('reason'), $request->user());

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function transfer(TransferEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $newClass = SchoolClass::query()->findOrFail($request->validated('class_id'));
        $newPackageId = $request->validated('course_package_id');
        $newPackage = $newPackageId ? CoursePackage::query()->findOrFail($newPackageId) : null;

        $enrollment = $this->enrollments->transferClass(
            $enrollment,
            $newClass,
            $request->user(),
            $request->validated('table_id'),
            $newPackage,
            $request->validated('fee_type'),
        );

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    /**
     * Reseats a student within their current class only — no class/course
     * change is possible through this action, see ChangeEnrollmentTableRequest
     * and EnrollmentPolicy::changeTable(). Updates in place and logs an
     * EnrollmentTransferHistory row, same as transfer().
     */
    public function changeTable(ChangeEnrollmentTableRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->changeTable($enrollment, $request->validated('table_id'), $request->user());

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    /**
     * Class > Students > Note: one note per student in the class, saved for
     * the whole class at once (like Add score). An empty note clears it.
     */
    public function saveNotes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['required', 'array', 'min:1', 'max:500'],
            'notes.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'notes.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $enrollments = Enrollment::query()->whereKey(array_column($data['notes'], 'enrollment_id'))->get()->keyBy('id');
        if ($enrollments->count() !== count($data['notes'])) {
            throw ValidationException::withMessages(['notes' => __('validation.exists', ['attribute' => 'enrollment'])]);
        }
        foreach ($enrollments as $enrollment) {
            $this->authorize('note', $enrollment);
        }

        DB::connection((new Enrollment)->getConnectionName())->transaction(function () use ($data, $enrollments) {
            foreach ($data['notes'] as $row) {
                $note = trim((string) ($row['note'] ?? ''));
                $enrollments[$row['enrollment_id']]->update(['note' => $note === '' ? null : $note]);
            }
        });

        return ApiResponse::success(['saved' => count($data['notes'])]);
    }

    /**
     * Class > Students > Responsible Staff: the active staff to pick from.
     * Own endpoint so a teacher with this permission does not need the
     * whole Staff list permission.
     */
    public function responsibleStaffOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ENROLLMENTS_RESPONSIBLE_STAFF), 403);

        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code]);

        return ApiResponse::success($staff);
    }

    /**
     * Class > Students > Responsible Staff: one staff member for all the
     * checked students, from a date (to date optional). History is kept: a
     * period still open on the new From date ends the day before; one that
     * starts on the same From date is replaced.
     */
    public function assignResponsibleStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'enrollment_ids.*' => ['required', 'integer', 'distinct'],
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        $enrollments = Enrollment::query()->whereKey($data['enrollment_ids'])->get();
        if ($enrollments->count() !== count($data['enrollment_ids'])) {
            throw ValidationException::withMessages(['enrollment_ids' => __('validation.exists', ['attribute' => 'enrollment'])]);
        }
        foreach ($enrollments as $enrollment) {
            $this->authorize('assignResponsibleStaff', $enrollment);
        }

        $from = $data['from_date'];
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        DB::connection('tenant')->transaction(function () use ($data, $from, $dayBefore, $request) {
            foreach ($data['enrollment_ids'] as $enrollmentId) {
                EnrollmentResponsibleStaff::query()->where('enrollment_id', $enrollmentId)->where('from_date', $from)->delete();
                EnrollmentResponsibleStaff::query()->where('enrollment_id', $enrollmentId)
                    ->where('from_date', '<', $from)
                    ->where(fn ($q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $from))
                    ->update(['to_date' => $dayBefore]);
                EnrollmentResponsibleStaff::query()->create([
                    'enrollment_id' => $enrollmentId,
                    'staff_id' => $data['staff_id'],
                    'from_date' => $from,
                    'to_date' => $data['to_date'] ?? null,
                    'assigned_by' => $request->user()?->id,
                ]);
            }
        });

        return ApiResponse::success(['saved' => count($data['enrollment_ids'])]);
    }

    /**
     * Class > Students > Export Excel: No., Table, Full name, Gender, Book,
     * Responsible staff, Note for the checked students, in the order the roster shows them.
     * Headers and gender words come from the screen (`labels`), so the file
     * is in the language the user is reading.
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', Enrollment::class);

        $data = $request->validate([
            'ids' => ['required', 'string', 'max:10000', 'regex:/^\d+(,\d+)*$/'],
            'title' => ['nullable', 'string', 'max:255'],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['nullable', 'string', 'max:60'],
        ]);
        $ids = array_values(array_unique(array_map('intval', explode(',', $data['ids']))));
        $label = fn (string $key, string $default): string => (string) ($data['labels'][$key] ?? $default);

        $enrollments = Enrollment::query()->whereKey($ids)->with(['student', 'table', 'coursePackage', 'schoolClass', 'currentResponsibleStaff.staff'])->get()->keyBy('id');
        $gender = function (?string $value) use ($label): string {
            $code = strtolower(trim((string) $value));

            return match ($code) {
                'male', 'm' => $label('male', 'Male'),
                'female', 'f' => $label('female', 'Female'),
                'other' => $label('other', 'Other'),
                default => (string) $value,
            };
        };

        $options = new Options;
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(10, 2);
        $options->setColumnWidth(30, 3);
        $options->setColumnWidth(10, 4);
        $options->setColumnWidth(30, 5);
        $options->setColumnWidth(25, 6);
        $options->setColumnWidth(60, 7);
        $path = tempnam(sys_get_temp_dir(), 'class');
        $writer = new Writer($options);
        $writer->openToFile($path);

        $classes = $enrollments->map(fn (Enrollment $e) => $e->schoolClass?->name)->filter()->unique()->implode(', ');
        $writer->addRow(Row::fromValues([$data['title'] ?? $classes], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(
            [$label('no', 'No.'), $label('table', 'Table'), $label('name', 'Full name'), $label('gender', 'Gender'), $label('book', 'Book'), $label('responsible_staff', 'Responsible staff'), $label('note', 'Note')],
            (new Style)->setFontBold()->setBackgroundColor('F3F4F6'),
        ));
        $wrap = (new Style)->setShouldWrapText();
        $n = 0;
        foreach ($ids as $id) {
            $e = $enrollments->get($id);
            if ($e === null) {
                continue;
            }
            $writer->addRow(Row::fromValues([
                ++$n,
                $e->table?->name ?? '',
                $e->student?->fullName() ?? '',
                $gender($e->student?->gender),
                $e->coursePackage?->name ?? '',
                $e->currentResponsibleStaff?->staff?->fullName() ?? '',
                $e->note ?? '',
            ], $wrap));
        }
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="class-students.xlsx"',
        ]);
    }

    public function changeStatus(ChangeEnrollmentStatusRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->changeStatus(
            $enrollment,
            $request->validated('status'),
            $request->validated('reason'),
            $request->validated('effective_date'),
            $request->user(),
        );

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function statusHistory(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        $history = $enrollment->statusHistories()->with('changedBy')->latest('id')->get();

        return ApiResponse::success(EnrollmentStatusHistoryResource::collection($history));
    }

    public function transferHistory(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        $history = $enrollment->transferHistories()
            ->with(['changedBy', 'fromClass', 'toClass', 'fromTable', 'toTable', 'fromCoursePackage', 'toCoursePackage'])
            ->latest('id')
            ->get();

        return ApiResponse::success(EnrollmentTransferHistoryResource::collection($history));
    }
}
