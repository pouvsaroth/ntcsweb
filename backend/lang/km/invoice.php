<?php

declare(strict_types=1);

return [
    'invoice' => 'វិក្កយបត្រ',
    'bill_to' => 'ត្រូវទូទាត់ដោយ',
    'invoice_date' => 'កាលបរិច្ឆេទវិក្កយបត្រ',
    'due_date' => 'កាលបរិច្ឆេទត្រូវទូទាត់',
    'description' => 'បរិយាយ',
    'qty' => 'ចំនួន',
    'unit_price' => 'តម្លៃឯកតា',
    'discount' => 'បញ្ចុះតម្លៃ',
    'total' => 'សរុប',
    'subtotal' => 'សរុបរង',
    'tax' => 'ពន្ធ',
    'paid' => 'បានទូទាត់',
    'balance' => 'នៅសល់',
    'payment_history' => 'ប្រវត្តិទូទាត់',
    'method' => 'វិធីទូទាត់',
    'date' => 'កាលបរិច្ឆេទ',
    'amount' => 'ចំនួនទឹកប្រាក់',
    'notes' => 'ចំណាំ',

    'statuses' => [
        'draft' => 'សេចក្តីព្រាង',
        'issued' => 'បានចេញ',
        'partially_paid' => 'បានទូទាត់ខ្លះ',
        'paid' => 'បានទូទាត់ពេញ',
        'overdue' => 'ហួសកំណត់',
        'cancelled' => 'បានលុបចោល',
        'void' => 'មិនត្រឹមត្រូវ',
    ],

    'methods' => [
        'cash' => 'សាច់ប្រាក់',
        'bank_transfer' => 'ផ្ទេរប្រាក់តាមធនាគារ',
        'aba' => 'ABA',
        'acleda' => 'ACLEDA',
        'card' => 'កាត',
        'other' => 'ផ្សេងទៀត',
        'qr' => 'QR',
    ],

    'greeting' => 'សូមគោរពជូន :name,',
    'issued_notice' => 'វិក្កយបត្ររបស់អ្នកត្រូវបានចេញ។',
    'attached_notice' => 'សូមមើលវិក្កយបត្ររបស់អ្នកដែលបានភ្ជាប់មកជាមួយ។',
    'thanks' => 'សូមអរគុណ,',
];
