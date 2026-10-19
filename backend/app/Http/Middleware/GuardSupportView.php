<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Platform\Support\SupportViewSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A browser session opened from the back office as a support view
 * (docs/backoffice/stage-6-support.md, 6b-2) is read-only, logged and short:
 *
 *   - ended, timed out or not this user any more → signed out, 401
 *   - sign-out → ends the view (not recorded as the user's logout)
 *   - anything but a read → refused, 403 "Read-only support view"
 *   - every request → one line in bo_support_session_events
 *
 * Ordinary sessions and Bearer tokens pass straight through.
 */
final readonly class GuardSupportView
{
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private SupportViewSession $views) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->views->idFrom($request);

        // No view, or a new ticket being used (it ends this view itself).
        if ($id === null || $request->routeIs('api.v1.auth.support-view')) {
            return $next($request);
        }

        $session = $this->views->live($request, $id);

        if ($request->routeIs('api.v1.auth.logout')) {
            if ($session !== null) {
                $this->views->record($id, $request, 200);
            }
            $this->views->signOut($request, $session);

            return ApiResponse::success(message: __('Signed out.'));
        }

        if ($session === null) {
            $this->views->drop($request);

            return ApiResponse::error(__('This support view has ended.'), 401, 'SUPPORT_VIEW_ENDED');
        }

        if (! in_array($request->method(), self::READS, true)) {
            $this->views->record($id, $request, 403);

            return ApiResponse::error(__('Read-only support view: nothing can be changed.'), 403, 'SUPPORT_VIEW_READ_ONLY');
        }

        $response = $next($request);
        $this->views->record($id, $request, $response->getStatusCode());

        return $response;
    }
}
