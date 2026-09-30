<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service: turn phone notifications (Web Push) on or off for the
 * current browser/device — identity-gated, same pattern as
 * NotificationController. See SendPushNotificationJob for delivery.
 */
final class MyPushSubscriptionController extends Controller
{
    /** The VAPID public key the browser needs to subscribe; null = push isn't configured on this server. */
    public function config(): JsonResponse
    {
        return ApiResponse::success(['public_key' => config('services.webpush.public_key')]);
    }

    /**
     * Upserts by endpoint — the same device re-subscribing (or a different
     * user signing in on a shared device) takes the row over rather than
     * duplicating it, so a push is never sent to someone who signed out.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint'])],
            [
                'user_id' => $request->user()->getKey(),
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ],
        );

        return ApiResponse::success(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        return ApiResponse::success(['subscribed' => false]);
    }
}
