<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A help article NewTech writes for every school, in English and Khmer; for one console module or general.
 * See docs/backoffice/stage-13-help-support.md.
 */
class HelpArticle extends Model
{
    public const STATUSES = ['draft', 'published'];

    protected $table = 'bo_help_articles';

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
