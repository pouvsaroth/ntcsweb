<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Accounting\AccountType;
use App\Support\Audit\AuditAction;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. One node in the Chart of Accounts — see the migration's
 * docblock. Uses the generic Auditable trait (unlike Invoice/Payment/
 * FinancialTransaction): editing an account's name/type/parent is a simple
 * field change a column-diff describes perfectly well, unlike a financial
 * event that needs a hand-written narrative.
 *
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property int|null $parent_id
 * @property bool $is_bank_or_cash
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'type', 'parent_id', 'description', 'is_bank_or_cash', 'is_active'])]
class Account extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    /** @use HasFactory<AccountFactory> */
    protected $attributes = [
        'is_bank_or_cash' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_bank_or_cash' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeBankOrCash(Builder $query): void
    {
        $query->where('is_bank_or_cash', true);
    }

    /**
     * +1 for a debit-normal type (Asset/Expense: debits increase it), -1 for
     * a credit-normal type (Liability/Equity/Revenue: credits increase it).
     * Multiply this by (debits - credits) to read any account's balance as a
     * natural positive number regardless of type — see
     * AccountingReportService.
     */
    public function normalBalanceSign(): int
    {
        return AccountType::isDebitNormal($this->type) ? 1 : -1;
    }

    public function auditModule(): string
    {
        return 'Accounting';
    }

    public function auditDisplayName(): string
    {
        return "{$this->code} - {$this->name}";
    }

    /**
     * Deactivating/reactivating an account is the one field change worth
     * calling out specifically — same convention Student/Enrollment/User
     * already use for their own `status`-like fields, reusing the shared
     * STATUS_CHANGE action rather than inventing a per-model name.
     *
     * @param  array<string, mixed>  $dirty
     */
    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('is_active', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return ($new['is_active'] ?? false)
                ? "Reactivated account {$this->auditDisplayName()}"
                : "Deactivated account {$this->auditDisplayName()}";
        }

        return null;
    }
}
