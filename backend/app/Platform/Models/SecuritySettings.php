<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's one sign-in security row (id 1): is an authenticator code required? */
class SecuritySettings extends Model
{
    protected $table = 'bo_security_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['require_two_factor' => 'boolean'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['require_two_factor' => true]);
    }

    /** Does staff sign-in need a code from an authenticator app right now? */
    public static function twoFactorRequired(): bool
    {
        return self::current()->require_two_factor;
    }
}
