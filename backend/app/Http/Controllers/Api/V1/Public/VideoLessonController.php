<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public "Video Lesson" page: courses (that have `show_videos` on and at
 * least one active video) grouped with their videos. Unauthenticated by
 * design — gated only by `tenant.required` on the route group, same as
 * every other public endpoint — but *optionally* aware of who's asking:
 * `$request->user()` resolves here (no `auth:sanctum` route middleware
 * needed) because `EnsureFrontendRequestsAreStateful` runs globally on every
 * api request and turns a first-party SPA's session cookie into an
 * authenticated one before this ever runs — see bootstrap/app.php.
 *
 * Access rule: a signed-in student sees every video in a course they're
 * actively enrolled in; everyone else (a guest, or a student browsing a
 * course they're not enrolled in) only gets the tenant-wide first 3 videos
 * (by course name, then video sort order) as free previews. A "locked"
 * video's `embed_url` is deliberately omitted from the response — the lock
 * is enforced here, not just hidden in the UI, since the raw URL would
 * otherwise be sitting in the network tab regardless of what the frontend
 * does with it.
 */
final class VideoLessonController extends Controller
{
    private const FREE_PREVIEW_COUNT = 3;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? $request->user('sanctum');

        $enrolledPackageIds = $user?->student !== null
            ? $user->student->enrollments()->active()->pluck('course_package_id')->all()
            : [];

        $freeVideoIds = Video::query()->active()
            ->whereHas('coursePackage', fn ($query) => $query->where('show_videos', true)->where('is_active', true))
            ->with('coursePackage:id,name')
            ->get()
            ->sortBy(fn (Video $video) => sprintf('%s|%05d|%05d', $video->coursePackage->name, $video->sort_order, $video->id))
            ->take(self::FREE_PREVIEW_COUNT)
            ->pluck('id');

        $packages = CoursePackage::query()
            ->where('show_videos', true)
            ->active()
            ->whereHas('videos', fn ($query) => $query->active())
            ->with(['videos' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get();

        return ApiResponse::success($packages->map(function (CoursePackage $package) use ($enrolledPackageIds, $freeVideoIds) {
            $courseUnlocked = in_array($package->id, $enrolledPackageIds, true);

            return [
                'id' => $package->id,
                'name' => $package->name,
                'videos' => $package->videos->map(function (Video $video) use ($courseUnlocked, $freeVideoIds) {
                    $unlocked = $courseUnlocked || $freeVideoIds->contains($video->id);

                    return [
                        'id' => $video->id,
                        'title' => $video->title,
                        'description' => $video->description,
                        'thumbnail_url' => $video->thumbnailUrl(),
                        'is_locked' => ! $unlocked,
                        'embed_url' => $unlocked ? $video->embedUrl() : null,
                    ];
                }),
            ];
        }));
    }
}
