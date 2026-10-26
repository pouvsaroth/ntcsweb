<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\Gradebook\GradebookAccess;
use App\Services\Academic\Gradebook\GradebookService;
use App\Services\Academic\Gradebook\ResultDocuments;
use App\Services\Academic\Gradebook\StudentResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Academic > Examination: Academic Transcript, Student Progress and Result
 * Export (docs/academics/gradebook.md, 7d), and the student's own results.
 *
 * A student's results are open to whoever may open one of the classes the
 * student is (or was) in — or every class (manage-all / approve).
 */
final class StudentResultsController extends Controller
{
    public function __construct(
        private readonly GradebookAccess $access,
        private readonly GradebookService $gradebook,
        private readonly StudentResults $results,
        private readonly ResultDocuments $documents,
    ) {}

    /** The class's students, for the transcript / progress picker. */
    public function students(Request $request): JsonResponse
    {
        $class = $this->classFrom($request);

        return ApiResponse::success($this->gradebook->roster($class)->map(fn (Student $s) => ['id' => $s->id, 'code' => $s->student_code, 'name' => $s->fullName()])->values());
    }

    public function transcript(Request $request): JsonResponse
    {
        $student = $this->studentFrom($request);

        return ApiResponse::success($this->results->transcript($student, $request->boolean('approved_only')));
    }

    public function transcriptPdf(Request $request): Response
    {
        $student = $this->studentFrom($request);

        return $this->file($this->documents->transcript($student), 'application/pdf', 'transcript-'.ResultDocuments::slug($student->student_code ?: (string) $student->id).'.pdf');
    }

    public function studentProgress(Request $request): JsonResponse
    {
        return ApiResponse::success($this->results->progress($this->studentFrom($request)));
    }

    public function classProgress(Request $request): JsonResponse
    {
        return ApiResponse::success($this->results->classProgress($this->classFrom($request)));
    }

    public function reportCards(Request $request): Response
    {
        [$class, $term] = [$this->classFrom($request), $this->termFrom($request)];
        $studentId = $request->integer('student_id') ?: null;
        $name = 'report-cards-'.ResultDocuments::slug($class->name.'-'.$term->name).'.pdf';

        return $this->file($this->documents->reportCards($class, $term, $studentId), 'application/pdf', $name);
    }

    public function scoreSheet(Request $request): Response
    {
        [$class, $term] = [$this->classFrom($request), $this->termFrom($request)];

        return $this->file(
            $this->documents->scoreSheet($class, $term),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'scores-'.ResultDocuments::slug($class->name.'-'.$term->name).'.xlsx',
        );
    }

    /** My Scores: the signed-in student's own approved results. */
    public function mine(Request $request): JsonResponse
    {
        $student = $request->user()->student;
        if ($student === null) {
            return ApiResponse::success(['student' => null, 'approved_only' => true, 'years' => []]);
        }

        return ApiResponse::success($this->results->transcript($student, approvedOnly: true));
    }

    private function classFrom(Request $request): SchoolClass
    {
        $id = $request->validate(['class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')]])['class_id'];
        $class = SchoolClass::query()->findOrFail($id);
        abort_unless($this->access->canView($request->user(), $class), 403);

        return $class;
    }

    private function termFrom(Request $request): AcademicTerm
    {
        $id = $request->validate(['academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')]])['academic_term_id'];

        return AcademicTerm::query()->findOrFail($id);
    }

    /** A student whose classes (any of them) this account may open. */
    private function studentFrom(Request $request): Student
    {
        $id = $request->validate(['student_id' => ['required', 'integer', Rule::exists('tenant.students', 'id')]])['student_id'];
        $student = Student::query()->findOrFail($id);
        abort_unless($this->maySee($request->user(), $student), 403);

        return $student;
    }

    private function maySee(User $user, Student $student): bool
    {
        if ($this->access->viewAll($user)) {
            return true;
        }
        $classIds = Enrollment::query()->where('student_id', $student->getKey())->pluck('class_id')->filter()->unique();

        return $this->access->classes($user)->whereIn('id', $classIds->all())->isNotEmpty();
    }

    private function file(string $bytes, string $type, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
