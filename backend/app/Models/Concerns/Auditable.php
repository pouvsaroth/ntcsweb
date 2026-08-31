<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Arr;

/**
 * Automatic CREATE/UPDATE/DELETE/RESTORE auditing via Eloquent model events —
 * a controller never has to remember to call AuditLogger itself for the
 * common case. To audit a new model: `use Auditable;` and implement
 * auditModule(); everything else below has a working default.
 *
 * Only changed columns are ever recorded on UPDATE (see auditableDirty()) —
 * an update that only touches `updated_at` produces no audit row at all.
 *
 * AuditLog itself must never use this trait — see AuditLogger's own docblock
 * for why a write can't recurse regardless (it goes through DB::table(), not
 * Eloquent), but this is the reason no *other* model should either: it would
 * just be auditing the audit trail's own bookkeeping.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (self $model) {
            $model->recordAudit(AuditAction::CREATE, new: $model->auditableSnapshot());
        });

        static::updated(function (self $model) {
            $dirty = $model->auditableDirty();

            if ($dirty === []) {
                return;
            }

            $old = $model->auditableEnrich(Arr::only($model->getOriginal(), array_keys($dirty)));
            $new = $model->auditableEnrich($dirty);
            $action = $model->auditActionForDirty($dirty);

            $model->recordAudit($action, old: $old, new: $new, description: $model->auditDescriptionForChange($action, $old, $new));
        });

        static::deleted(function (self $model) {
            // Fires for both a soft delete and the soft-delete half of a
            // force-delete (see SoftDeletes::forceDelete()) — either way this
            // is the last moment the row's attributes are still intact.
            $model->recordAudit(AuditAction::DELETE, old: $model->auditableSnapshot());
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (self $model) {
                $model->recordAudit(AuditAction::RESTORE, new: $model->auditableSnapshot());
            });
        }
    }

    /** Human module name for filtering/description — "Students", "Staff", "Roles". */
    abstract public function auditModule(): string;

    /**
     * The record's natural business identifier for a description —
     * "NTS-000001", a name, ... `array_key_exists` against the raw attribute
     * array, not `getAttribute()` directly: Model::shouldBeStrict() throws
     * for a column that doesn't exist on this model at all (e.g. Enrollment
     * has no `name`), rather than just returning null.
     */
    public function auditDisplayName(): string
    {
        $attributes = $this->getAttributes();

        foreach (['student_code', 'employee_code', 'title', 'name'] as $column) {
            $value = $attributes[$column] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * Extra columns to leave out of this model's audit snapshots/diffs,
     * beyond the trait's own defaults (primary key, tenant_id, timestamps,
     * soft-delete column, and anything already marked #[Hidden]).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return [];
    }

    /**
     * Dirty/snapshot columns that should also carry a human-readable label
     * alongside the raw id — e.g. `position_id` resolving to the Position's
     * name, so the audit detail view can show "HR Manager", not just "7".
     *
     * @return array<string, callable(mixed): (string|null)>
     */
    protected function auditLabels(): array
    {
        return [];
    }

    /**
     * Lets a model turn a specific column change into a more specific action
     * than a blanket UPDATE. Student/Staff/User all use this for `status`;
     * Staff also uses it for `position_id`.
     *
     * @param  array<string, mixed>  $dirty
     */
    protected function auditActionForDirty(array $dirty): string
    {
        return AuditAction::UPDATE;
    }

    /**
     * A richer description for a derived action (see auditActionForDirty) —
     * return null to fall back to AuditLogger's generic "Updated {module} {name}".
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableSnapshot(): array
    {
        return $this->auditableEnrich(Arr::except($this->attributesToArray(), $this->auditExcludedColumns()));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableDirty(): array
    {
        return Arr::except($this->getDirty(), $this->auditExcludedColumns());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditableEnrich(array $values): array
    {
        foreach ($this->auditLabels() as $column => $resolve) {
            if (array_key_exists($column, $values)) {
                $values[preg_replace('/_id$/', '', $column).'_label'] = $resolve($values[$column]);
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function auditExcludedColumns(): array
    {
        return array_values(array_unique(array_merge(
            [$this->getKeyName(), 'tenant_id', 'created_at', 'updated_at', 'deleted_at'],
            $this->getHidden(),
            $this->auditExcept(),
        )));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function recordAudit(string $action, array $old = [], array $new = [], ?string $description = null): void
    {
        app(AuditLogger::class)->log($action, $this->auditModule(), $this, $old, $new, $description);
    }
}
