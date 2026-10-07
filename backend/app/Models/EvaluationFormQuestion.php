<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One question of an evaluation form — rated 1–5 or answered
 * in words. Saved with its form (see EvaluationFormController).
 */
#[Fillable(['evaluation_form_id', 'section', 'question', 'type', 'is_required', 'sort_order'])]
class EvaluationFormQuestion extends Model
{
    public const TYPE_RATING = 'rating';

    public const TYPE_TEXT = 'text';

    public const TYPES = [self::TYPE_RATING, self::TYPE_TEXT];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'evaluation_form_id');
    }
}
