<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FormCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A department/category grouping in the eApprovals "Forms" catalog — see the
 * migration's docblock. Browsing (index) is unguarded, like the leave
 * request submission path; only managing the catalog itself needs a
 * permission (see FormCategoryPolicy).
 *
 * @property int $tenant_id
 * @property string $name
 */
#[Fillable(['name', 'order', 'is_active'])]
class FormCategory extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<FormCategoryFactory> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(FormTemplate::class);
    }

    public function auditModule(): string
    {
        return 'Approvals';
    }
}
