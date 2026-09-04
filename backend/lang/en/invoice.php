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
