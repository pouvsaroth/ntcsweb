<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\User;
use App\Platform\Models\SupportSession;
use App\Platform\Models\SupportSessionEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The console side of a support view (stage 6b-2): turn the one-time ticket
 * into a browser session as the school user, marked as a support view; find
 * that mark on later requests; end it. See docs/backoffice/stage-6-support.md.
 *
 * Entering is deliberately NOT a sign-in: no last_login_at, no device slot
 * (user_sessions), no LOGIN audit row. The school's activity log line was
 * already written when staff opened the view.
 */
final class SupportViewSession
{
    /** The session key that marks a browser session as a support view. */
    public const KEY = 'ntcs_support_session_id';

    /**
     * @param  int|null  $explicitTenantId  the school this request names (its domain, or the
     *                                      tenant header/field on a central domain), if any
     */
    public function enter(Request $request, string $ticket, ?int $explicitTenantId): SupportSession
    {
        return self::inUtc(fn () => $this->enterInUtc($request, $ticket, $explicitTenantId));
    }

    private function enterInUtc(Request $request, string $ticket, ?int $explicitTenantId): SupportSession
    {
        $refused = fn () => ValidationException::withMessages([
            'ticket' => __('This support link has expired or was already used. Open a new support view from the back office.'),
        ]);

        $session = SupportSession::query()->with(['user' => fn ($q) => $q->withoutGlobalScopes(), 'tenant'])
            ->where('ticket_hash', SupportViews::hash($ticket))
            ->first();

        if ($session === null
            || $session->ticket_used_at !== null
            || $session->ticket_expires_at->isPast()
            || ! $session->isOpen()
            || $session->user === null
            || ! $session->user->isActive()
            || (int) $session->user->tenant_id !== (int) $session->tenant_id
            || ! $session->tenant?->isActive()
            || ($explicitTenantId !== null && $explicitTenantId !== (int) $session->tenant_id)) {
            throw $refused();
        }

        // Spend the ticket atomically: two tabs racing with the same link get one view.
        $spent = SupportSession::query()->whereKey($session->getKey())->whereNull('ticket_used_at')
            ->update(['ticket_used_at' => now(), 'updated_at' => now()]);
        if ($spent !== 1) {
            throw $refused();
        }

        // Whoever was signed in to the console in this browser is signed out
        // first (their device slot freed, an earlier support view ended), so
        // nothing of theirs carries over.
        $earlier = $this->idFrom($request);
        if ($earlier !== null) {
            SupportSession::query()->whereKey($earlier)->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_by' => SupportSession::ENDED_BY_SIGN_OUT, 'updated_at' => now()]);
        }
        $previous = Auth::guard('web')->user();
        if ($previous instanceof User) {
            $previous->forgetLoginSession($request->session()->getId());
        }
        $request->session()->invalidate();

        Auth::guard('web')->login($session->user);
        $request->session()->regenerate();
        $request->session()->put(self::KEY, $session->getKey());

        return $session->refresh();
    }

    /**
     * Back office rows are written in UTC, but a console request runs in the
     * school's own timezone (ResolveTenant), and the timestamp columns carry
     * no zone. Every read and write of them here runs in UTC, or a view would
     * look 7 hours older than it is.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function inUtc(callable $callback): mixed
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            return $callback();
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /** The support view this request's browser session is in, or null for an ordinary session. */
    public function idFrom(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The live support view for this request, or null when it has ended,
     * timed out, or does not belong to the signed-in user. A view found past
     * its time is stamped as timed out.
     */
    public function live(Request $request, int $id): ?SupportSession
    {
        return self::inUtc(fn () => $this->liveInUtc($id));
    }

    private function liveInUtc(int $id): ?SupportSession
    {
        $session = SupportSession::query()->with('staff:id,name')->find($id);
        $user = Auth::guard('web')->user();

        if ($session === null || ! $user instanceof User || (int) $user->getKey() !== (int) $session->user_id) {
            return null;
        }

        if ($session->ended_at === null && $session->expires_at->isPast()) {
            $session->forceFill(['ended_at' => $session->expires_at, 'ended_by' => SupportSession::ENDED_BY_TIMEOUT])->save();
        }

        return $session->isOpen() ? $session : null;
    }

    /** End the view from the console (sign-out) and drop the browser session. */
    public function signOut(Request $request, ?SupportSession $session): void
    {
        if ($session !== null && $session->ended_at === null) {
            self::inUtc(fn () => $session->forceFill(['ended_at' => now(), 'ended_by' => SupportSession::ENDED_BY_SIGN_OUT])->save());
        }

        $this->drop($request);
    }

    /** Sign the browser out without touching the user's own sessions or device slots. */
    public function drop(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // The sanctum guard keeps the user it resolved; drop it too, so
        // nothing later in a long-lived process still sees them signed in.
        Auth::forgetGuards();
    }

    /** One line per request: method, path, status — never the data or the query string. */
    public function record(int $sessionId, Request $request, ?int $status): void
    {
        try {
            SupportSessionEvent::query()->insert([
                'support_session_id' => $sessionId,
                'method' => Str::limit($request->method(), 10, ''),
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 500, ''),
                'status' => $status,
                'at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // The page log must never be the reason a support view breaks.
            report($e);
        }
    }

    /** What the console shows in its red bar. @return array<string, mixed> */
    public static function present(SupportSession $session): array
    {
        return [
            'id' => $session->id,
            'staff' => $session->staff?->name,
            'expires_at' => self::inUtc(fn () => $session->expires_at->toIso8601String()),
        ];
    }

    /** For callers that only need to know whether a view is open (e.g. /auth/me). */
    public function current(Request $request): ?SupportSession
    {
        $id = $this->idFrom($request);

        return $id === null ? null : $this->live($request, $id);
    }

}
