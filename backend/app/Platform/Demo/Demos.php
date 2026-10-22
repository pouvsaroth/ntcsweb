<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\DemoVisit;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\SupportViewSession;
use App\Services\Academic\StaffIdGenerator;
use App\Services\Academic\StudentIdGenerator;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * "Try the demo" from the website (docs/backoffice/stage-12-demo.md).
 *
 * Every visitor gets their own account in the demo school: a user with the
 * chosen role plus a staff or student record in the school's own database.
 * They never see a password — the website hands them a one-time ticket that
 * the console turns into an ordinary signed-in session.
 *
 * bo_demo_visits rows are written in UTC like the rest of the back office;
 * a website or console request runs in the school's timezone, so every read
 * and write of them goes through SupportViewSession::inUtc().
 */
final class Demos
{
    /** How long a sign-in ticket works: long enough for the redirect, no more. */
    public const TICKET_MINUTES = 2;

    /** The position each staff role is given in the demo school. */
    private const POSITIONS = [
        DemoVisit::ROLE_DIRECTOR => [Role::SCHOOL_ADMIN, 'School Director'],
        DemoVisit::ROLE_TEACHER => [Role::TEACHER, 'Teacher'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly DatabaseManager $databases,
        private readonly StaffIdGenerator $staffIds,
        private readonly StudentIdGenerator $studentIds,
        private readonly PlatformAudit $platformAudit,
        private readonly AuditLogger $audit,
    ) {}

    /** The demo school, or a clear error when it is missing or switched off. */
    public function school(): Tenant
    {
        $slug = (string) config('platform.demo_school');
        $school = $slug === '' ? null : Tenant::query()->where('slug', $slug)->first();

        if ($school === null || ! $school->isActive()) {
            throw ValidationException::withMessages(['demo' => __('The demo is not available right now. Please contact us.')]);
        }

        return $school;
    }

    /**
     * A new visitor: their account and record in the demo school, the back
     * office row, and a first sign-in ticket.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null, role: string, locale?: string|null}  $data
     * @return array{visit: DemoVisit, ticket: string}
     */
    public function start(array $data, Request $request): array
    {
        $school = $this->school();

        [$user, $staffId, $studentId] = $this->inSchool($school, fn () => $this->createAccount($school, $data));

        return SupportViewSession::inUtc(function () use ($school, $data, $request, $user, $staffId, $studentId) {
            $visit = DemoVisit::query()->create([
                'token' => (string) Str::uuid(),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => isset($data['email']) ? Str::lower(trim($data['email'])) : null,
                'role' => $data['role'],
                'tenant_id' => $school->getKey(),
                'user_id' => $user->getKey(),
                'staff_id' => $staffId,
                'student_id' => $studentId,
                'expires_at' => now()->addDays(max(1, (int) config('platform.demo_days', 7))),
                'locale' => $data['locale'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            ]);

            $this->platformAudit->record('demo.started', $visit, new: [
                'name' => $visit->name, 'role' => $visit->role, 'phone' => $visit->phone, 'email' => $visit->email,
            ], tenantId: $school->getKey());

            return ['visit' => $visit, 'ticket' => $this->issueTicket($visit)];
        });
    }

    /** "Continue my demo": a new ticket for the same account, while it still works. */
    public function resume(string $token): array
    {
        return SupportViewSession::inUtc(function () use ($token) {
            $visit = DemoVisit::query()->with('user')->where('token', $token)->first();

            if ($visit === null || ! $visit->isLive() || $visit->user === null || ! $visit->user->isActive()) {
                throw ValidationException::withMessages(['demo' => __('This demo has ended. Start a new one.')]);
            }

            return ['visit' => $visit, 'ticket' => $this->issueTicket($visit)];
        });
    }

    /** Where the browser goes to be signed in. */
    public function signInUrl(DemoVisit $visit, string $ticket): string
    {
        $slug = Tenant::query()->whereKey($visit->tenant_id)->value('slug');

        return rtrim((string) config('platform.console_url'), '/').'/demo?'.http_build_query([
            'ticket' => $ticket,
            'tenant' => $slug,
        ]);
    }

    /**
     * The console end: spend the ticket and sign the browser in as the
     * visitor's demo account — an ordinary sign-in (last login, device slot,
     * LOGIN audit), unlike a support view.
     */
    public function enter(Request $request, string $ticket, ?int $explicitTenantId): User
    {
        $refused = fn () => ValidationException::withMessages([
            'ticket' => __('This demo link has expired or was already used. Go back to the website and try again.'),
        ]);

        $visit = SupportViewSession::inUtc(function () use ($ticket, $explicitTenantId, $refused) {
            $hash = hash('sha256', $ticket);
            $visit = DemoVisit::query()->with(['user', 'tenant'])->where('ticket_hash', $hash)->first();

            if ($visit === null
                || $visit->ticket_expires_at === null || $visit->ticket_expires_at->isPast()
                || ! $visit->isLive()
                || $visit->user === null || ! $visit->user->isActive()
                || (int) $visit->user->tenant_id !== (int) $visit->tenant_id
                || ! $visit->tenant?->isActive()
                || ($explicitTenantId !== null && $explicitTenantId !== (int) $visit->tenant_id)) {
                throw $refused();
            }

            // Spend it atomically: two tabs racing with the same link get one sign-in.
            $spent = DemoVisit::query()->whereKey($visit->getKey())->where('ticket_hash', $hash)
                ->update(['ticket_hash' => null, 'ticket_expires_at' => null, 'sign_ins' => $visit->sign_ins + 1,
                    'last_sign_in_at' => now(), 'updated_at' => now()]);
            if ($spent !== 1) {
                throw $refused();
            }

            return $visit;
        });

        $user = $visit->user;

        // Whoever was signed in to the console in this browser is signed out
        // first, so nothing of theirs carries over.
        $previous = Auth::guard('web')->user();
        if ($previous instanceof User) {
            $previous->forgetLoginSession($request->session()->getId());
        }
        $request->session()->invalidate();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->recordLoginSession($request->session()->getId(), $request->ip(), $request->userAgent());
        $user->recordLogin($request->ip());
        $this->audit->logFor(AuditAction::LOGIN, 'Auth', $user->tenant_id, $user, ['transport' => 'demo']);

        return $user;
    }

    /** Hourly: switch off demo accounts past their days. Returns how many. */
    public function endExpired(): int
    {
        return SupportViewSession::inUtc(function () {
            $ended = 0;

            DemoVisit::query()->whereNull('ended_at')->where('expires_at', '<=', now())
                ->orderBy('id')->limit(500)->get()
                ->each(function (DemoVisit $visit) use (&$ended) {
                    $visit->forceFill(['ended_at' => now(), 'ticket_hash' => null, 'ticket_expires_at' => null])->save();
                    User::query()->withoutGlobalScopes()->whereKey($visit->user_id)
                        ->where('status', User::STATUS_ACTIVE)
                        ->update(['status' => User::STATUS_SUSPENDED, 'updated_at' => now()]);
                    $this->platformAudit->record('demo.ended', $visit, tenantId: $visit->tenant_id);
                    $ended++;
                });

            return $ended;
        });
    }

    /** @return array{0: User, 1: int|null, 2: int|null} */
    private function createAccount(Tenant $school, array $data): array
    {
        $role = Role::query()->withoutGlobalScopes()
            ->where('tenant_id', $school->getKey())
            ->where('slug', $data['role'] === DemoVisit::ROLE_STUDENT ? Role::STUDENT : self::POSITIONS[$data['role']][0])
            ->first()
            ?? throw ValidationException::withMessages(['demo' => __('The demo is not available right now. Please contact us.')]);

        $phone = filled($data['phone'] ?? null) ? (PhoneNumber::normalize($data['phone']) ?? $data['phone']) : null;
        $email = filled($data['email'] ?? null) ? Str::lower(trim($data['email'])) : null;

        // The login is made up and the password is random: the visitor only
        // ever comes in through a ticket. Their phone and email are contact
        // details, never the login, so one person can try every role.
        $user = new User;
        $user->forceFill([
            'name' => $data['name'],
            'email' => 'demo-'.Str::lower(Str::random(12)).'@demo.local',
            'password' => Hash::make(Str::random(48)),
            'status' => User::STATUS_ACTIVE,
            'locale' => $data['locale'] ?? null,
            'tenant_id' => $school->getKey(),
        ])->save();
        $user->attachRoles($role);

        try {
            if ($data['role'] === DemoVisit::ROLE_STUDENT) {
                $student = Student::query()->create([
                    'user_id' => $user->getKey(),
                    'student_code' => $this->studentIds->next($school),
                    'first_name' => $data['name'],
                    'email' => $email,
                    'phone' => $phone,
                    'enrollment_date' => now()->toDateString(),
                    'status' => Student::STATUS_ACTIVE,
                ]);

                return [$user, null, (int) $student->getKey()];
            }

            $position = $this->position($role, self::POSITIONS[$data['role']][1]);
            $staff = Staff::query()->create([
                'position_id' => $position->getKey(),
                'employee_code' => $this->staffIds->next($school),
                'first_name' => $data['name'],
                'email' => $email,
                'phone' => $phone ?? '-',
                'hire_date' => now()->toDateString(),
                'status' => Staff::STATUS_ACTIVE,
            ]);
            $staff->forceFill(['user_id' => $user->getKey()])->save();

            return [$user, (int) $staff->getKey(), null];
        } catch (\Throwable $e) {
            $user->roles()->detach();
            $user->forceDelete();

            throw $e;
        }
    }

    /** The demo school's position for this role, made the first time it is needed. */
    private function position(Role $role, string $name): Position
    {
        return Position::query()->where('role_id', $role->getKey())->where('status', 'active')->orderBy('id')->first()
            ?? Position::query()->create(['name' => $name, 'role_id' => $role->getKey(), 'status' => 'active']);
    }

    /** One-time, short-lived; only its hash is kept. */
    private function issueTicket(DemoVisit $visit): string
    {
        $ticket = Str::random(48);
        $visit->forceFill([
            'ticket_hash' => hash('sha256', $ticket),
            'ticket_expires_at' => now()->addMinutes(self::TICKET_MINUTES),
        ])->save();

        return $ticket;
    }

    /**
     * Step into the demo school (tenant context + its database) while
     * $callback runs, then back into whatever school the request was in —
     * the website's own, on a public request.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inSchool(Tenant $school, callable $callback): mixed
    {
        $previous = $this->context->get();
        $testing = app()->environment('testing');

        $this->context->set($school);
        if (! $testing) {
            $this->databases->createTenantConnection($school);
        }

        try {
            return $callback();
        } finally {
            if (! $testing) {
                $this->databases->purgeTenantConnection();
            }
            if ($previous !== null) {
                $this->context->set($previous);
                if (! $testing) {
                    $this->databases->createTenantConnection($previous);
                }
            } else {
                $this->context->forget();
            }
        }
    }
}
