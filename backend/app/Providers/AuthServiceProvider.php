<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Book;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\GalleryImage;
use App\Models\HomeSlide;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Position;
use App\Models\Product;
use App\Models\Program;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\BookPolicy;
use App\Policies\ClassroomPolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\GalleryImagePolicy;
use App\Policies\HomeSlidePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PositionPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\RolePolicy;
use App\Policies\SchoolClassPolicy;
use App\Policies\StaffPolicy;
use App\Policies\StudentPolicy;
use App\Policies\TeacherPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Support\Authorization\PermissionRegistry;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        Tenant::class => TenantPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Teacher::class => TeacherPolicy::class,
        Student::class => StudentPolicy::class,
        Classroom::class => ClassroomPolicy::class,
        Book::class => BookPolicy::class,
        SchoolClass::class => SchoolClassPolicy::class,
        Enrollment::class => EnrollmentPolicy::class,
        HomeSlide::class => HomeSlidePolicy::class,
        GalleryImage::class => GalleryImagePolicy::class,
        Program::class => ProgramPolicy::class,
        Position::class => PositionPolicy::class,
        Staff::class => StaffPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        Product::class => ProductPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            PermissionRegistry::class,
            fn () => new PermissionRegistry(
                Cache::store(config('auth.rbac.cache_store'))
            ),
        );

        $this->app->when(PermissionRegistry::class)
            ->needs(CacheRepository::class)
            ->give(fn () => Cache::store(config('auth.rbac.cache_store')));
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(function (User $user, string $ability): ?bool {
            // Platform super admins bypass every check. Granting by identity
            // rather than by holding every permission row means a newly added
            // capability reaches them with no backfill.
            if ($user->isSuperAdmin()) {
                return true;
            }

            // Bare permission slugs ("students.create") are answered straight
            // from RBAC. Policy abilities ("view", "viewAny", "update") contain
            // no dot, so they fall through to the policy untouched.
            if (str_contains($ability, '.') && $user->hasPermission($ability)) {
                return true;
            }

            // null, not false — anything else here would short-circuit policies.
            return null;
        });
    }
}
