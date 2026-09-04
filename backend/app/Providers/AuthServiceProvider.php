<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetIssue;
use App\Models\AssetLocation;
use App\Models\AssetMaintenance;
use App\Models\AssetRepair;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\Building;
use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\CoursePackage;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\GalleryImage;
use App\Models\HomeSlide;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\LeaveRequest;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\Payment;
use App\Models\Position;
use App\Models\Product;
use App\Models\Program;
use App\Models\RepairShop;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudyMode;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Video;
use App\Policies\AcademicProgramPolicy;
use App\Policies\AcademicYearPolicy;
use App\Policies\AccountPolicy;
use App\Policies\AssetCategoryPolicy;
use App\Policies\AssetIssuePolicy;
use App\Policies\AssetLocationPolicy;
use App\Policies\AssetMaintenancePolicy;
use App\Policies\AssetPolicy;
use App\Policies\AssetRepairPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BookCategoryPolicy;
use App\Policies\BookPolicy;
use App\Policies\BuildingPolicy;
use App\Policies\ClassroomPolicy;
use App\Policies\ClassroomTablePolicy;
use App\Policies\CoursePackagePolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FinancialTransactionPolicy;
use App\Policies\GalleryImagePolicy;
use App\Policies\HomeSlidePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LanguagePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\LookupCategoryPolicy;
use App\Policies\LookupValuePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PositionPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\RepairShopPolicy;
use App\Policies\RolePolicy;
use App\Policies\SchoolClassPolicy;
use App\Policies\StaffPolicy;
use App\Policies\StudentPolicy;
use App\Policies\StudyModePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
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
        Student::class => StudentPolicy::class,
        Classroom::class => ClassroomPolicy::class,
        ClassroomTable::class => ClassroomTablePolicy::class,
        Building::class => BuildingPolicy::class,
        Book::class => BookPolicy::class,
        BookCategory::class => BookCategoryPolicy::class,
        SchoolClass::class => SchoolClassPolicy::class,
        Enrollment::class => EnrollmentPolicy::class,
        StudyMode::class => StudyModePolicy::class,
        AcademicProgram::class => AcademicProgramPolicy::class,
        CoursePackage::class => CoursePackagePolicy::class,
        Video::class => VideoPolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
        AcademicYear::class => AcademicYearPolicy::class,
        Language::class => LanguagePolicy::class,
        LookupCategory::class => LookupCategoryPolicy::class,
        LookupValue::class => LookupValuePolicy::class,
        HomeSlide::class => HomeSlidePolicy::class,
        GalleryImage::class => GalleryImagePolicy::class,
        Program::class => ProgramPolicy::class,
        Position::class => PositionPolicy::class,
        Staff::class => StaffPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        Product::class => ProductPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
        AttendanceRecord::class => AttendancePolicy::class,
        Account::class => AccountPolicy::class,
        Expense::class => ExpensePolicy::class,
        FinancialTransaction::class => FinancialTransactionPolicy::class,
        Asset::class => AssetPolicy::class,
        AssetCategory::class => AssetCategoryPolicy::class,
        AssetLocation::class => AssetLocationPolicy::class,
        Department::class => DepartmentPolicy::class,
        Supplier::class => SupplierPolicy::class,
        RepairShop::class => RepairShopPolicy::class,
        AssetIssue::class => AssetIssuePolicy::class,
        AssetRepair::class => AssetRepairPolicy::class,
        AssetMaintenance::class => AssetMaintenancePolicy::class,
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
