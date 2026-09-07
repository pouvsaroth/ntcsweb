<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FormTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A requestable form type in the eApprovals "Forms" catalog — see the
 * migration's docblock and ApprovalRequest, which is a submission against one.
 *
 * @property int $tenant_id
 * @property int $form_category_id
 * @property string $code
 * @property string $name
 */
#[Fillable(['form_category_id', 'code', 'name', 'description', 'order', 'is_active'])]
class FormTemplate extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<FormTemplateFactory> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FormCategory::class, 'form_category_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function auditModule(): string
    {
        return 'Approvals';
    }
}
