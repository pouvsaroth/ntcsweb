<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveStaffCardDesignRequest;
use App\Http\Resources\StaffCardDesignResource;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StaffCardDesign;
use App\Models\Student;
use App\Support\Authorization\ClassAccess;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** HRM > Staff cards, Students > Student cards and Examination > Certificate designs: the school's designs (?card_for=staff|student|certificate). */
final class StaffCardDesignController extends Controller
{
    /** Uploaded photo inputs => their column. */
    private const FILES = [
        'front_background' => 'front_background_path',
        'back_background' => 'back_background_path',
        'signature' => 'signature_path',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $cardFor = in_array($request->query('card_for'), StaffCardDesign::CARD_FOR, true) ? $request->query('card_for') : StaffCardDesign::FOR_STAFF;
        $this->authorize('viewAny', [StaffCardDesign::class, $cardFor]);

        $designs = ApiQuery::for(StaffCardDesign::query()->where('card_for', $cardFor), $request)
            ->searchable('name')
            ->sortable(['name', 'updated_at', 'created_at'])
            ->paginate();

        return ApiResponse::success(StaffCardDesignResource::collection($designs));
    }

    public function show(StaffCardDesign $design): JsonResponse
    {
        $this->authorize('view', $design);

        return ApiResponse::success(new StaffCardDesignResource($design));
    }

    public function store(SaveStaffCardDesignRequest $request): JsonResponse
    {
        $design = new StaffCardDesign([
            'card_for' => $request->cardFor(),
            ...$request->safe()->only(['name', 'width_mm', 'height_mm', 'has_front', 'has_back']),
            'front_fields' => $request->fields('front_fields') ?? [],
            'back_fields' => $request->fields('back_fields') ?? [],
            'created_by' => $request->user()->getKey(),
        ]);
        $this->applyFiles($request, $design);
        $design->save();

        return ApiResponse::created(new StaffCardDesignResource($design));
    }

    public function update(SaveStaffCardDesignRequest $request, StaffCardDesign $design): JsonResponse
    {
        $design->fill($request->safe()->only(['name', 'width_mm', 'height_mm', 'has_front', 'has_back']));
        foreach (['front_fields', 'back_fields'] as $side) {
            $fields = $request->fields($side);
            if ($fields !== null) {
                $design->{$side} = $fields;
            }
        }
        $old = $this->applyFiles($request, $design);
        $design->save();
        $this->deleteFiles($old);

        return ApiResponse::success(new StaffCardDesignResource($design));
    }

    public function destroy(StaffCardDesign $design): JsonResponse
    {
        $this->authorize('delete', $design);

        $paths = array_map(fn (string $column) => $design->{$column}, array_values(self::FILES));
        $design->delete();
        $this->deleteFiles($paths);

        return ApiResponse::noContent();
    }

    /**
     * Students > Student cards > print: the students to print, each with what
     * a card can show — the class, course, program and year of their current
     * enrollment (or of the picked class) and a guardian's phone. Limited to
     * the user's own classes when their role says so (Roles > Data view).
     */
    public function students(Request $request): JsonResponse
    {
        $this->authorize('viewAny', [StaffCardDesign::class, StaffCardDesign::FOR_STUDENT]);
        $request->validate([
            'class_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $classId = $request->integer('class_id') ?: null;
        $current = [Enrollment::STATUS_ACTIVE, Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_EXAM_READY];

        $query = Student::query()
            ->with([
                'guardians' => fn ($q) => $q->orderBy('id'),
                'enrollments' => fn ($q) => $q
                    ->when($classId, fn ($e) => $e->where('class_id', $classId), fn ($e) => $e->whereIn('status', $current))
                    ->with(['schoolClass.academicYear', 'coursePackage', 'academicProgram'])
                    ->latest('id'),
            ])
            ->when($classId, fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->where('class_id', $classId)->whereNotIn('status', [Enrollment::STATUS_DROPPED])))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower((string) $request->input('search')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(first_name) LIKE ?', [$term])->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(english_name) LIKE ?', [$term])->orWhereRaw('LOWER(student_code) LIKE ?', [$term]));
            })
            ->orderBy('student_code');
        app(ClassAccess::class)->limitStudents($query, $request->user());

        $rows = $query->limit(500)->get()->map(function (Student $student): array {
            $enrollment = $student->enrollments->first();
            $class = $enrollment?->schoolClass;

            return [
                'id' => $student->id,
                'student_code' => $student->student_code,
                'full_name' => $student->fullName(),
                'english_name' => $student->english_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'phone' => $student->phone,
                'photo_url' => $student->photoUrl(),
                'enrollment_date' => $student->enrollment_date?->toDateString(),
                'class_name' => $class?->name,
                'course' => $enrollment?->coursePackage?->name,
                'program' => $enrollment?->academicProgram?->name,
                'academic_year' => $class instanceof SchoolClass ? $class->academicYear?->name : null,
                'guardian_phone' => $student->guardians->first()?->phone,
            ];
        });

        return ApiResponse::success($rows);
    }

    /**
     * Store new uploads and clear removed photos on the model (not saved yet).
     *
     * @return list<string|null> the replaced paths, to delete once saved
     */
    private function applyFiles(SaveStaffCardDesignRequest $request, StaffCardDesign $design): array
    {
        $old = [];
        foreach (self::FILES as $input => $column) {
            if ($request->hasFile($input)) {
                $old[] = $design->{$column};
                $path = $request->file($input)->store($this->context->getOrFail()->storagePath('staff-cards'), 'public');
                abort_if($path === false, 500, 'Failed to store the uploaded photo.');
                $design->{$column} = $path;
            } elseif ($request->boolean("remove_{$input}")) {
                $old[] = $design->{$column};
                $design->{$column} = null;
            }
        }

        return $old;
    }

    /** @param  list<string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }
}
