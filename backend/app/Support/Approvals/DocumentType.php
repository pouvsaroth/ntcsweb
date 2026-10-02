<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Models\ApprovalRequest;
use App\Models\ExamApplication;
use App\Models\LeaveRequest;
use App\Models\MakeUpClassRequest;
use App\Models\ResignationRequest;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every kind of item that goes through the Approvals queue, and so can have
 * its own approval flow (see App\Services\Approvals\ApprovalFlow). Leave
 * requests are one table but two items — a student's permission request and
 * a staff member's leave usually go to different people. Every custom
 * eApprovals form template shares the one FORM_REQUEST item.
 */
final class DocumentType
{
    public const STUDENT_LEAVE = 'student_leave';

    public const STAFF_LEAVE = 'staff_leave';

    public const RESIGNATION = 'resignation';

    public const MAKE_UP_CLASS = 'make_up_class';

    public const EXAM_APPLICATION = 'exam_application';

    public const FORM_REQUEST = 'form_request';

    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        self::STUDENT_LEAVE => LeaveRequest::class,
        self::STAFF_LEAVE => LeaveRequest::class,
        self::RESIGNATION => ResignationRequest::class,
        self::MAKE_UP_CLASS => MakeUpClassRequest::class,
        self::EXAM_APPLICATION => ExamApplication::class,
        self::FORM_REQUEST => ApprovalRequest::class,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MODELS);
    }

    public static function forModel(Model $model): string
    {
        if ($model instanceof LeaveRequest) {
            return $model->staff_id !== null ? self::STAFF_LEAVE : self::STUDENT_LEAVE;
        }

        $type = array_search($model::class, self::MODELS, true);

        if ($type === false) {
            throw new \InvalidArgumentException('Not an approvable document: '.$model::class);
        }

        return $type;
    }

    /**
     * Every item stored in this model's table — two for LeaveRequest.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    public static function forModelClass(string $modelClass): array
    {
        return array_keys(array_filter(self::MODELS, fn (string $class) => $class === $modelClass));
    }

    /** Narrows a query on the item's table down to just this item's rows. */
    public static function constrain(Builder $query, string $type): void
    {
        match ($type) {
            self::STUDENT_LEAVE => $query->whereNull($query->qualifyColumn('staff_id')),
            self::STAFF_LEAVE => $query->whereNotNull($query->qualifyColumn('staff_id')),
            default => null,
        };
    }

    /** The permission that decides this item when it has no flow of its own. */
    public static function approvePermission(string $type): string
    {
        return match ($type) {
            self::STUDENT_LEAVE, self::STAFF_LEAVE => Permissions::LEAVE_REQUESTS_APPROVE,
            self::RESIGNATION => Permissions::RESIGNATION_REQUESTS_APPROVE,
            self::MAKE_UP_CLASS => Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE,
            self::EXAM_APPLICATION => Permissions::EXAM_APPLICATIONS_APPROVE,
            self::FORM_REQUEST => Permissions::APPROVAL_REQUESTS_APPROVE,
        };
    }
}
