<?php

// Phone (Web Push) notification text — see SendPushNotificationJob. Keyed by
// NotificationType; the in-app bell renders the same types from the
// frontend's own `notifications.types.*` translations instead.
return [
    'fallback' => 'You have a new notification',
    'leave_request_submitted' => ':student_name submitted a permission request',
    'leave_request_approved' => 'The permission request for :student_name was approved',
    'resignation_request_submitted' => ':staff_name submitted a resignation request',
    'resignation_request_approved' => 'The resignation request for :staff_name was approved',
    'make_up_class_request_submitted' => ':student_name submitted a make-up class request',
    'make_up_class_request_approved' => 'The make-up class request for :student_name was approved',
    'student_registration_submitted' => ':student_name registered and is waiting for approval',
];
