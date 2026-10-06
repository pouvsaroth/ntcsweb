<?php

// Phone (Web Push) notification text — see SendPushNotificationJob. Keyed by
// NotificationType; the in-app bell renders the same types from the
// frontend's own `notifications.types.*` translations instead.
return [
    'fallback' => 'You have a new notification',
    'leave_request_submitted' => ':student_name submitted a permission request',
    'leave_request_approved' => 'The permission request for :student_name was approved',
    'leave_request_rejected' => 'The permission request for :student_name was rejected: :reason',
    'resignation_request_submitted' => ':staff_name submitted a resignation request',
    'resignation_request_approved' => 'The resignation request for :staff_name was approved',
    'resignation_request_rejected' => 'The resignation request for :staff_name was rejected: :reason',
    'make_up_class_request_submitted' => ':student_name submitted a make-up class request',
    'make_up_class_request_approved_to_study' => 'The make-up class request for :student_name was approved to study',
    'make_up_class_request_approved' => 'The make-up class request for :student_name was approved',
    'make_up_class_request_rejected' => 'The make-up class request for :student_name was rejected: :reason',
    'exam_application_submitted' => ':student_name applied for the exam',
    'exam_application_approved' => 'The exam application for :student_name was approved',
    'exam_application_rejected' => 'The exam application for :student_name was rejected: :reason',
    'approval_request_submitted' => ':requester_name submitted ":subject" for approval',
    'approval_request_approved' => 'Your request ":subject" was approved',
    'approval_request_rejected' => 'Your request ":subject" was rejected: :reason',
    'student_registration_submitted' => ':student_name registered and is waiting for approval',
    'manpower_request_submitted' => ':requester_name asked to hire :headcount × :job_title (:reference)',
    'manpower_request_approved' => 'Manpower request :reference (:job_title) was approved',
    'manpower_request_rejected' => 'Manpower request :reference (:job_title) was rejected: :reason',
    'job_application_received' => ':applicant_name applied for :job_title',
    'interview_scheduled' => 'You are interviewing :applicant_name (:job_title) on :date',
];
