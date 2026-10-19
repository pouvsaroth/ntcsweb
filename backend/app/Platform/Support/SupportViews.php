<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SupportSession;
use App\Platform\Models\SupportSettings;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Read-only support views of a school, back office side (stage 6b-1):
 * open one (a one-time ticket for the console), end one, show them.
 * See docs/backoffice/stage-6-support.md.
 */
final class SupportViews
{
    /** A view can be opened only into an active school: the console refuses everyone else (suspended included). */
    private const OPEN_STATUSES = [Tenant::STATUS_ACTIVE];

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly AuditLogger $schoolLog,
        private readonly Request $request,
    ) {}

    /**
     * Open a view of $tenant as $user. Returns the session and the console
     * address carrying the ticket — the only time the ticket exists unhashed.
     *
     * @return array{session: SupportSession, url: string}
     */
    public function open(Tenant $tenant, User $user, string $reason, PlatformStaff $staff): array
    {
        if (! in_array($tenant->status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['school' => __('A support view needs an active school: a suspended, archived or still being set up school cannot be signed in to.')]);
        }
        if ((int) $user->tenant_id !== (int) $tenant->getKey()) {
            throw ValidationException::withMessages(['user_id' => __('This user is not in this school.')]);
        }
        if ($user->status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['user_id' => __('Only an active user can be viewed as.')]);
        }

        $ticket = Str::random(48);
        $now = now();
        $minutes = SupportSettings::current()->duration_minutes;

        $session = DB::connection((new SupportSession)->getConnectionName())->transaction(function () use ($tenant, $user, $reason, $staff, $ticket, $now, $minutes) {
            $session = SupportSession::query()->create([
                'tenant_id' => $tenant->getKey(),
                'staff_id' => $staff->getKey(),
                'user_id' => $user->getKey(),
                'reason' => $reason,
                'ticket_hash' => self::hash($ticket),
                'ticket_expires_at' => $now->copy()->addSeconds(SupportSession::TICKET_SECONDS),
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes($minutes),
                'ip' => $this->request->ip(),
            ]);

            $this->audit->record('support.view_opened', $session, new: [
                'user_id' => $user->getKey(), 'user' => $user->email, 'reason' => $reason, 'minutes' => $minutes,
            ], tenantId: $tenant->getKey(), actor: $staff);

            return $session;
        });

        // The school sees it in its own activity log. Not their sign-in: no LOGIN row.
        $this->schoolLog->log(
            AuditAction::SUPPORT_VIEW,
            'Auth',
            $user,
            new: ['reason' => $reason, 'until' => $session->expires_at->toIso8601String()],
            description: "Support (NewTech staff) viewed as {$user->name}: {$reason}",
            tenantId: (int) $tenant->getKey(),
        );

        return ['session' => $session, 'url' => $this->consoleUrl($tenant, $ticket)];
    }

    /** End an open view now; the console refuses its next request. */
    public function end(SupportSession $session, PlatformStaff $staff): SupportSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages(['session' => __('This support view has already ended.')]);
        }

        DB::connection($session->getConnectionName())->transaction(function () use ($session, $staff) {
            $session->forceFill([
                'ended_at' => now(),
                'ended_by' => SupportSession::ENDED_BY_STAFF,
                'ended_by_staff_id' => $staff->getKey(),
            ])->save();

            $this->audit->record('support.view_ended', $session, tenantId: $session->tenant_id, actor: $staff);
        });

        return $session;
    }

    public static function hash(string $ticket): string
    {
        return hash('sha256', $ticket);
    }

    /** The console's own address (where every school signs in), with the school and the ticket. */
    private function consoleUrl(Tenant $tenant, string $ticket): string
    {
        return rtrim((string) config('platform.console_url'), '/').'/support-view?'.http_build_query([
            'ticket' => $ticket,
            'tenant' => $tenant->slug,
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(SupportSession $s, bool $withEvents = false): array
    {
        $endedBy = $s->ended_by ?? ($s->isOpen() ? null : SupportSession::ENDED_BY_TIMEOUT);

        return [
            'id' => $s->id,
            'state' => $s->state(),
            'school' => $s->tenant ? ['id' => $s->tenant->id, 'name' => $s->tenant->name, 'slug' => $s->tenant->slug] : null,
            'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name] : null,
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
            'reason' => $s->reason,
            'started_at' => $s->started_at->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
            'ended_at' => ($s->ended_at ?? ($endedBy === SupportSession::ENDED_BY_TIMEOUT ? $s->expires_at : null))?->toIso8601String(),
            'ended_by' => $endedBy,
            'ended_by_staff' => $s->endedByStaff?->name,
            'entered' => $s->ticket_used_at !== null,
            'ip' => $s->ip,
            'events_count' => (int) ($s->events_count ?? $s->events()->count()),
            'events' => $withEvents
                ? $s->events()->orderBy('at')->orderBy('id')->limit(1000)->get()
                    ->map(fn ($e) => ['method' => $e->method, 'path' => $e->path, 'status' => $e->status, 'at' => $e->at->toIso8601String()])
                    ->all()
                : null,
        ];
    }
}
