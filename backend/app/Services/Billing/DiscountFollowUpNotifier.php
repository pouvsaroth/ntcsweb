<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Enrollment;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;

/**
 * A student just completed a course: tell everyone who can give discount
 * codes, linking to Students to add discount (see Enrollment::booted()).
 */
final class DiscountFollowUpNotifier
{
    public const LINK = '/admin/invoices/discount-codes/students';

    public function __construct(private readonly NotificationService $notifications) {}

    public function completed(Enrollment $enrollment): void
    {
        $enrollment->loadMissing(['student', 'coursePackage']);

        $this->notifications->notifyMany(
            $this->notifications->usersWithPermission(Permissions::DISCOUNT_CODES_UPDATE),
            NotificationType::STUDENT_COMPLETED_ADD_DISCOUNT,
            [
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'student_name' => $enrollment->student?->fullName() ?? '—',
                'course' => $enrollment->coursePackage?->name ?? '—',
            ],
            link: self::LINK,
        );
    }
}
