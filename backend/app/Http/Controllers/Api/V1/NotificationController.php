<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\UserNotification;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service, identity-gated the same way as MyLeaveRequestController: a
 * user only ever sees/acts on their own notifications, so no permission is
 * required or checked here.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Computed before ApiQuery::for()/paginate() below, which mutate the
        // builder in place (limit/offset/order) — a query built afterward
        // would inherit those instead of counting the whole unread set.
        $unreadCount = UserNotification::query()->where('recipient_id', $request->user()->id)->unread()->count();

        $query = UserNotification::query()->where('recipient_id', $request->user()->id);

        $notifications = ApiQuery::for($query, $request)
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(
            UserNotificationResource::collection($notifications),
            meta: ['unread_count' => $unreadCount],
        );
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->recipient_id === $request->user()->id, 404);

        $notification->markRead();

        return ApiResponse::success(new UserNotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::query()
            ->where('recipient_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return ApiResponse::success(message: __('All notifications marked as read.'));
    }
}
