<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: the courses they're actively studying, each with
 * its own video list — "click a course, see its videos," unlike the public
 * Video Lesson page (see VideoLessonController), which lists every course
 * and locks the ones a visitor isn't enrolled in. Every course returned is
 * one of the student's own active enrollments; its videos are theirs to
 * watch unless they pay that course by month (Student::videoCourseIds()):
 * then the course is `locked` and no video has an `embed_url` — enforced
 * here, not only hidden in the UI. Identity-gated through `$user->student`,
 * same pattern as MyAttendanceController.
 */
final class MyVideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $courses = $student->videoCourseIds();
        $packageIds = [...$courses['watch'], ...$courses['monthly']];

        $packages = CoursePackage::query()
            ->whereIn('id', $packageIds)
            ->where('show_videos', true)
            ->whereHas('videos', fn ($query) => $query->active())
            ->with(['videos' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id')])
            ->ordered()
            ->get();

        return ApiResponse::success($packages->map(function (CoursePackage $package) use ($courses) {
            $locked = ! in_array($package->id, $courses['watch'], true);

            return [
                'id' => $package->id,
                'name' => $package->name,
                'thumbnail_url' => $package->thumbnailUrl(),
                'video_count' => $package->videos->count(),
                // 'monthly': paid by month, so no video may be watched.
                'locked' => $locked ? 'monthly' : null,
                'videos' => $package->videos->map(fn (Video $video) => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'description' => $video->description,
                    'thumbnail_url' => $video->thumbnailUrl(),
                    'is_locked' => $locked,
                    'embed_url' => $locked ? null : $video->embedUrl(),
                ]),
            ];
        }));
    }

    private function studentOrFail(Request $request)
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        return $student;
    }
}
