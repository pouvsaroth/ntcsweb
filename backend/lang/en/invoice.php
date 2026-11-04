<?php

declare(strict_types=1);

/**
 * Every label on the invoice PDF/email — see resources/views/pdf/invoice.blade.php
 * and resources/views/mail/invoice.blade.php. Picked up automatically via
 * app()->getLocale(), which ResolveTenant already sets to the tenant's own
 * `locale` per request — see SchoolSettingsController for where a school
 * picks it.
 */
return [
    'invoice' => 'INVOICE',
    'bill_to' => 'Bill To',
    'student_id' => 'Student ID',
    'name' => 'Name',
    'english_name' => 'English Name',
    'tel' => 'Tel',
    'enrollment_code' => 'Enrollment Code',
    'class' => 'Class',
    'company' => 'Company',
    'contact_person' => 'Contact',
    'tax_number' => 'Tax No.',
    'address' => 'Address',
    'period' => 'Period',
    'students' => 'Students',
    'student_count' => ':count students',
    'no' => 'No.',
    'time' => 'Time',
    'invoice_date' => 'Invoice Date',
    'due_date' => 'Due Date',
    'description' => 'Description',
    'qty' => 'Qty',
    'unit_price' => 'Unit Price',
    'discount' => 'Discount',
    'total' => 'Total',
    'subtotal' => 'Subtotal',
    'tax' => 'Tax',
    'paid' => 'Paid',
    'balance' => 'Balance',
    'payment_history' => 'Payment History',
    'method' => 'Method',
    'date' => 'Date',
    'amount' => 'Amount',
    'notes' => 'Notes',
    'scan_to_pay' => 'Scan to pay (KHQR)',

    // Class days, ISO numbering as ClassSchedule::day_of_week (1 = Monday).
    'days' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],

    // Receipt PDF (resources/views/pdf/receipt.blade.php).
    'receipt' => 'RECEIPT',
    'student' => 'Student',
    'invoice_number' => 'Invoice',
    'payment_method' => 'Payment Method',
    'reference' => 'Reference',
    'invoice_total' => 'Invoice Total',
    'remaining_balance' => 'Remaining Balance',
    'received_from' => 'Received From',
    'payment_date' => 'Payment Date',
    'paid_before' => 'Paid Before',
    'amount_received' => 'Amount Received',

    // Invoice lines the system writes itself — see App\Support\Billing\InvoiceLineText.
    'lines' => [
        'enrollment' => 'Enrollment: :detail',
        'exam_fee' => 'Exam fee: :name',
        'company_class' => ':detail (:count students), :from to :to',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'issued' => 'Issued',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
        'void' => 'Void',
    ],

    'methods' => [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'aba' => 'ABA',
        'acleda' => 'ACLEDA',
        'card' => 'Card',
        'other' => 'Other',
        'qr' => 'QR',
    ],

    // The emailed notification (resources/views/mail/invoice.blade.php).
    'greeting' => 'Dear :name,',
    'issued_notice' => 'Your invoice has been issued.',
    'attached_notice' => 'Please find your invoice attached.',
    'thanks' => 'Thanks,',
];
