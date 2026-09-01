<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The audit action catalog — mirrors Permissions' own "constants, not
 * scattered strings" convention. Kept to what the application actually
 * emits; add a new one only when a real call site needs it.
 */
final class AuditAction
{
    public const CREATE = 'CREATE';

    public const UPDATE = 'UPDATE';

    public const DELETE = 'DELETE';

    public const RESTORE = 'RESTORE';

    public const LOGIN = 'LOGIN';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';

    public const LOGOUT = 'LOGOUT';

    public const PASSWORD_CHANGE = 'PASSWORD_CHANGE';

    public const PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';

    public const EMAIL_VERIFIED = 'EMAIL_VERIFIED';

    public const ROLE_CHANGE = 'ROLE_CHANGE';

    public const STATUS_CHANGE = 'STATUS_CHANGE';

    public const POSITION_CHANGE = 'POSITION_CHANGE';

    // Billing — deliberately more specific than the generic CREATE/UPDATE
    // above: Invoice/Payment don't use the Auditable trait at all (see their
    // own docblocks), every one of these is an explicit call from
    // InvoiceService/PaymentService/InvoiceNotificationService, because a
    // financial event needs a richer description than column-diffing gives.
    public const INVOICE_CREATED = 'INVOICE_CREATED';

    public const INVOICE_UPDATED = 'INVOICE_UPDATED';

    public const INVOICE_CANCELLED = 'INVOICE_CANCELLED';

    public const INVOICE_VOIDED = 'INVOICE_VOIDED';

    public const PAYMENT_CREATED = 'PAYMENT_CREATED';

    public const PAYMENT_CANCELLED = 'PAYMENT_CANCELLED';

    public const PAYMENT_REFUNDED = 'PAYMENT_REFUNDED';

    public const RECEIPT_CREATED = 'RECEIPT_CREATED';

    public const INVOICE_SENT = 'INVOICE_SENT';

    public const INVOICE_SEND_FAILED = 'INVOICE_SEND_FAILED';

    // Attendance — one entry per batch save (a whole class roster on one
    // date), not one per student; see AttendanceRecord's docblock.
    public const ATTENDANCE_RECORDED = 'ATTENDANCE_RECORDED';

    // Accounting — Expense/FinancialTransaction don't use the Auditable
    // trait either, same reasoning as Invoice/Payment. Account itself DOES
    // use Auditable (a simple config record) and reuses the generic
    // CREATE/UPDATE/STATUS_CHANGE actions above rather than a bespoke set.
    public const EXPENSE_CREATED = 'EXPENSE_CREATED';

    public const EXPENSE_UPDATED = 'EXPENSE_UPDATED';

    public const EXPENSE_APPROVED = 'EXPENSE_APPROVED';

    public const EXPENSE_REJECTED = 'EXPENSE_REJECTED';

    public const EXPENSE_PAID = 'EXPENSE_PAID';

    public const EXPENSE_CANCELLED = 'EXPENSE_CANCELLED';

    public const TRANSACTION_POSTED = 'TRANSACTION_POSTED';

    public const TRANSACTION_REVERSED = 'TRANSACTION_REVERSED';

    public const TRANSFER_CREATED = 'TRANSFER_CREATED';

    public const ACCOUNTING_PERIOD_CLOSED = 'ACCOUNTING_PERIOD_CLOSED';

    // Assets — see Asset's own docblock for why it doesn't use the generic
    // Auditable trait either: AssetService/AssetLifecycleService/
    // AssetRepairService fire these explicitly with a business-readable
    // description, the same reasoning as Invoice/Payment/Expense. Distinct
    // from AssetHistory (see that model's docblock): this answers "who did
    // it in the system," AssetHistory answers "what happened to the asset."
    public const ASSET_CREATED = 'ASSET_CREATED';

    public const ASSET_UPDATED = 'ASSET_UPDATED';

    public const ASSET_ASSIGNED = 'ASSET_ASSIGNED';

    public const ASSET_RETURNED = 'ASSET_RETURNED';

    public const ASSET_TRANSFERRED = 'ASSET_TRANSFERRED';

    public const ASSET_ISSUE_REPORTED = 'ASSET_ISSUE_REPORTED';

    public const ASSET_ISSUE_UPDATED = 'ASSET_ISSUE_UPDATED';

    public const ASSET_ISSUE_RESOLVED = 'ASSET_ISSUE_RESOLVED';

    public const ASSET_SENT_TO_REPAIR = 'ASSET_SENT_TO_REPAIR';

    public const ASSET_REPAIR_STARTED = 'ASSET_REPAIR_STARTED';

    public const ASSET_REPAIR_COMPLETED = 'ASSET_REPAIR_COMPLETED';

    public const ASSET_REPAIR_CANCELLED = 'ASSET_REPAIR_CANCELLED';

    public const ASSET_MAINTENANCE_SCHEDULED = 'ASSET_MAINTENANCE_SCHEDULED';

    public const ASSET_MAINTENANCE_COMPLETED = 'ASSET_MAINTENANCE_COMPLETED';

    public const ASSET_RETIRED = 'ASSET_RETIRED';

    public const ASSET_DISPOSED = 'ASSET_DISPOSED';

    public const ASSET_LOST = 'ASSET_LOST';

    public const ASSET_FOUND = 'ASSET_FOUND';

    public const ASSET_STATUS_CHANGED = 'ASSET_STATUS_CHANGED';

    public const ASSET_CONDITION_CHANGED = 'ASSET_CONDITION_CHANGED';
}
