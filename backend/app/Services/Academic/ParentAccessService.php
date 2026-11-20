<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ParentStudent;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Parent portal logins (Students > a student > Guardians > Give parent access).
 *
 * One login per guardian phone: the parent signs in with that phone (or the
 * guardian's email) and sees every student whose guardians carry the same
 * phone — siblings are linked at once. A new login's temporary password is
 * the phone number, like student logins. Links live in parent_students,
 * because guardian rows are re-created on every student save.
 */
final class ParentAccessService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UserProvisioningService $provisioning,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{user: User, temporary_password: string|null, children: int, new_login: bool}
     */
    public function give(Student $student, StudentGuardian $guardian, User $actor): array
    {
        abort_unless($guardian->student_id === $student->id, 404);
        $phone = PhoneNumber::normalize((string) $guardian->phone);
        if ($phone === null) {
            throw ValidationException::withMessages(['guardian_id' => __('This guardian has no valid phone number. Add one in the student form first.')]);
        }
        $email = filled($guardian->email) ? mb_strtolower(trim((string) $guardian->email)) : null;

        // Logins are central, the links are in the school's database: both or neither.
        return DB::connection((new User)->getConnectionName())->transaction(fn () => DB::connection('tenant')->transaction(function () use ($student, $guardian, $phone, $email, $actor) {
            $role = $this->parentRole();

            $user = User::query()->inTenant($this->context->idOrFail())->where('phone', $phone)->first();
            $password = null;
            $new = false;
            if ($user !== null) {
                if ($user->hasRole(Role::STUDENT)) {
                    throw ValidationException::withMessages(['guardian_id' => __('This phone number is a student\'s login. Use another phone for the parent.')]);
                }
                if (! $user->hasRole(Role::PARENT)) {
                    // e.g. a teacher who is also a parent: the same login gets the Parent portal too.
                    $user->attachRoles($role);
                }
                if ($user->status !== User::STATUS_ACTIVE && $this->onlyParent($user)) {
                    $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
                }
            } else {
                $emailFree = $email !== null && ! User::query()->inTenant($this->context->idOrFail())->where('email', $email)->exists();
                $made = $this->provisioning->provision(['name' => $guardian->guardian_name, 'email' => $emailFree ? $email : null, 'phone' => $phone], $role);
                $user = $made['user'];
                $password = $made['temporary_password'];
                $new = true;
            }

            // This student, and every other student with a guardian on the same phone.
            $links = $this->studentsWithPhone($phone)->put($student->id, $guardian->guardian_type);
            foreach ($links as $studentId => $relation) {
                ParentStudent::query()->firstOrCreate(['user_id' => $user->id, 'student_id' => $studentId], ['relation' => $relation]);
            }

            $this->audit->log('parent_access.given', 'Students', $student, new: [
                'parent' => $user->name, 'phone' => $phone, 'new_login' => $new, 'children' => $links->count(),
            ], actor: $actor);

            return ['user' => $user, 'temporary_password' => $password, 'children' => count(ParentStudent::childIdsOf($user->id)), 'new_login' => $new];
        }));
    }

    /** Takes this student away from the parent; a parent-only login with no child left is switched off. */
    public function revoke(Student $student, User $parent, User $actor): void
    {
        DB::connection($parent->getConnectionName())->transaction(fn () => DB::connection('tenant')->transaction(function () use ($student, $parent, $actor) {
            ParentStudent::query()->where('user_id', $parent->id)->where('student_id', $student->id)->delete();
            if (ParentStudent::childIdsOf($parent->id) === []) {
                if ($this->onlyParent($parent)) {
                    $parent->forceFill(['status' => User::STATUS_INACTIVE])->save();
                    $parent->tokens()->delete();
                } else {
                    $parent->detachRoles(...$parent->roles()->where('slug', Role::PARENT)->get()->all());
                }
            }
            $this->audit->log('parent_access.removed', 'Students', $student, old: ['parent' => $parent->name, 'phone' => $parent->phone], actor: $actor);
        }));
    }

    /** "Forgot password": back to the phone number as a temporary password. */
    public function resetPassword(User $parent, User $actor): string
    {
        abort_unless($this->onlyParent($parent), 409, __('This login is also used for staff work. Reset its password in Settings > Users.'));
        $parent->forceFill(['password' => Hash::make((string) $parent->phone)])->save();
        $this->audit->log('parent_access.password_reset', 'Students', $parent, actor: $actor);

        return (string) $parent->phone;
    }

    /**
     * The guardians of a student, each with its parent login (if any), plus
     * logins linked to the student that no guardian phone matches any more.
     *
     * @return array{guardians: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public function overview(Student $student): array
    {
        $parents = $this->parentsOf($student);
        $byPhone = $parents->keyBy('phone');
        $used = [];
        $guardians = $student->guardians()->orderBy('id')->get()->map(function (StudentGuardian $g) use ($byPhone, &$used) {
            $phone = PhoneNumber::normalize((string) $g->phone);
            $parent = $phone !== null ? $byPhone->get($phone) : null;
            if ($parent !== null) {
                $used[] = $parent->id;
            }

            return [
                'id' => $g->id,
                'name' => $g->guardian_name,
                'type' => $g->guardian_type,
                'phone' => $g->phone,
                'email' => $g->email,
                'can_give' => $phone !== null,
                'parent' => $parent ? $this->present($parent) : null,
            ];
        })->values()->all();

        return [
            'guardians' => $guardians,
            'others' => $parents->whereNotIn('id', $used)->map(fn (User $u) => $this->present($u))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'status' => $u->status,
            'children_count' => count(ParentStudent::childIdsOf($u->id)),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'staff_login' => ! $this->onlyParent($u),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function parentsOf(Student $student): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->inTenant($this->context->idOrFail())
            ->whereIn('id', $student->parentLinks()->pluck('user_id'))->orderBy('name')->get();
    }

    /** @return Collection<int, string|null> student id => relation */
    private function studentsWithPhone(string $phone): Collection
    {
        return StudentGuardian::query()->get(['student_id', 'phone', 'guardian_type'])
            ->filter(fn (StudentGuardian $g) => PhoneNumber::normalize((string) $g->phone) === $phone)
            ->mapWithKeys(fn (StudentGuardian $g) => [$g->student_id => $g->guardian_type]);
    }

    /** The school's Parent role (made by RolePermissionSeeder; made here if a school does not have it yet). */
    private function parentRole(): Role
    {
        $tenantId = $this->context->idOrFail();
        $role = Role::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slug', Role::PARENT)->first();
        if ($role === null) {
            $role = new Role;
            $role->forceFill(['tenant_id' => $tenantId, 'slug' => Role::PARENT, 'name' => 'Parent', 'description' => null, 'level' => Role::LEVELS[Role::PARENT], 'is_system' => true])->save();
        }

        return $role;
    }

    private function onlyParent(User $user): bool
    {
        return $user->roles()->pluck('slug')->diff([Role::PARENT])->isEmpty();
    }
}
