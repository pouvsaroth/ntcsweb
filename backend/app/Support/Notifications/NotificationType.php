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

    /** One step of an approval flow approved — sent to the requester; see ApprovalFlow::approve(). */
    public const APPROVAL_STEP_APPROVED = 'approval_step_approved';

    public const PAYROLL_RUN_SUBMITTED = 'payroll_run_submitted';

    public const PAYROLL_RUN_APPROVED = 'payroll_run_approved';

    public const PAYROLL_RUN_REJECTED = 'payroll_run_rejected';

    public const EXPENSE_SUBMITTED = 'expense_submitted';

    public const EXPENSE_APPROVED = 'expense_approved';

    public const EXPENSE_REJECTED = 'expense_rejected';

    public const PERFORMANCE_SELF_ASSESSMENT_OPEN = 'performance_self_assessment_open';

    public const PERFORMANCE_MANAGER_ASSESSMENT_DUE = 'performance_manager_assessment_due';

    public const PERFORMANCE_REVIEW_COMPLETED = 'performance_review_completed';

    public const PROMOTION_SUBMITTED = 'promotion_submitted';

    public const PROMOTION_APPROVED = 'promotion_approved';

    public const PROMOTION_REJECTED = 'promotion_rejected';

    public const PROMOTION_APPLIED = 'promotion_applied';

    public const STUDENT_REGISTRATION_SUBMITTED = 'student_registration_submitted';

    public const MANPOWER_REQUEST_SUBMITTED = 'manpower_request_submitted';

    public const MANPOWER_REQUEST_APPROVED = 'manpower_request_approved';

    public const MANPOWER_REQUEST_REJECTED = 'manpower_request_rejected';

    public const JOB_APPLICATION_RECEIVED = 'job_application_received';

    public const INTERVIEW_SCHEDULED = 'interview_scheduled';

    public const OVERTIME_REQUEST_SUBMITTED = 'overtime_request_submitted';

    public const OVERTIME_REQUEST_APPROVED = 'overtime_request_approved';

    public const OVERTIME_REQUEST_REJECTED = 'overtime_request_rejected';

    public const ATTENDANCE_CORRECTION_SUBMITTED = 'attendance_correction_submitted';

    public const ATTENDANCE_CORRECTION_APPROVED = 'attendance_correction_approved';

    public const ATTENDANCE_CORRECTION_REJECTED = 'attendance_correction_rejected';
}
