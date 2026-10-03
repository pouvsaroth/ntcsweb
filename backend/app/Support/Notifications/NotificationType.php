<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Every UserNotification `type` value — the frontend's notifications.ts
 * looks up `notifications.types.{type}` in each locale to render one, so a
 * new type here always needs a matching translation key added there too.
 */
final class NotificationType
{
    public const LEAVE_REQUEST_SUBMITTED = 'leave_request_submitted';

    public const LEAVE_REQUEST_APPROVED = 'leave_request_approved';

    public const LEAVE_REQUEST_REJECTED = 'leave_request_rejected';

    public const RESIGNATION_REQUEST_SUBMITTED = 'resignation_request_submitted';

    public const RESIGNATION_REQUEST_APPROVED = 'resignation_request_approved';

    public const RESIGNATION_REQUEST_REJECTED = 'resignation_request_rejected';

    public const MAKE_UP_CLASS_REQUEST_SUBMITTED = 'make_up_class_request_submitted';

    public const MAKE_UP_CLASS_REQUEST_APPROVED_TO_STUDY = 'make_up_class_request_approved_to_study';

    public const MAKE_UP_CLASS_REQUEST_APPROVED = 'make_up_class_request_approved';

    public const MAKE_UP_CLASS_REQUEST_REJECTED = 'make_up_class_request_rejected';

    public const EXAM_APPLICATION_SUBMITTED = 'exam_application_submitted';

    public const EXAM_APPLICATION_APPROVED = 'exam_application_approved';

    public const EXAM_APPLICATION_REJECTED = 'exam_application_rejected';

    public const APPROVAL_REQUEST_SUBMITTED = 'approval_request_submitted';

    public const APPROVAL_REQUEST_APPROVED = 'approval_request_approved';

    public const APPROVAL_REQUEST_REJECTED = 'approval_request_rejected';

    public const STUDENT_REGISTRATION_SUBMITTED = 'student_registration_submitted';
}
