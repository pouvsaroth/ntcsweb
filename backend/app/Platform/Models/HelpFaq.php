<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A frequently asked question with its answer, in English and Khmer.
 * See docs/backoffice/stage-13-help-support.md.
 */
class HelpFaq extends Model
{
    public const STATUSES = ['draft', 'published'];

    protected $table = 'bo_help_faqs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
