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

    public const RESIGNATION_REQUEST_SUBMITTED = 'resignation_request_submitted';

    public const RESIGNATION_REQUEST_APPROVED = 'resignation_request_approved';

    public const STUDENT_REGISTRATION_SUBMITTED = 'student_registration_submitted';
}
