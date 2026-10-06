<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. One word/sentence of the app's text this school reworded,
 * for one language — see the migration's docblock and
 * TranslationOverrideController.
 *
 * @property string $locale
 * @property string $key
 * @property string $value
 */
#[Fillable(['locale', 'key', 'value'])]
class TranslationOverride extends Model
{
    use Auditable;

    /** The languages the app itself ships text in — mirrors SUPPORTED_LOCALES in the frontend's i18n/index.ts. */
    public const LOCALES = ['en', 'km', 'zh', 'ko', 'ja'];

    protected $connection = 'tenant';

    public function auditModule(): string
    {
        return 'Settings';
    }

    public function auditDisplayName(): string
    {
        return "{$this->locale}: {$this->key}";
    }
}
