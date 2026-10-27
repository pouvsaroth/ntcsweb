<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * One email / one phone = one account in the whole system, across every
 * customer (and the platform's own accounts). Emails compare without case,
 * phones without spaces or dashes.
 *
 * Accounts made before this rule may still share an email or phone; they
 * are left as they are; only new or changed values are checked.
 */
final class UniqueAccountIdentity implements ValidationRule
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    /**
     * @param  int|null  $ignoreUserId  the account being edited
     * @param  int|null  $ignoreTenantId  accounts of this customer are fine (e.g. a new owner picked from its own users)
     * @param  string|null  $message  instead of the default message
     */
    public function __construct(
        private readonly string $field,
        private readonly ?int $ignoreUserId = null,
        private readonly ?int $ignoreTenantId = null,
        private readonly ?string $message = null,
    ) {}

    public static function email(?int $ignoreUserId = null, ?int $ignoreTenantId = null, ?string $message = null): self
    {
        return new self(self::EMAIL, $ignoreUserId, $ignoreTenantId, $message);
    }

    public static function phone(?int $ignoreUserId = null, ?int $ignoreTenantId = null, ?string $message = null): self
    {
        return new self(self::PHONE, $ignoreUserId, $ignoreTenantId, $message);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (self::taken($this->field, $value, $this->ignoreUserId, $this->ignoreTenantId)) {
            $fail($this->message ?? ($this->field === self::EMAIL
                ? __('This email already has an account.')
                : __('This phone number already has an account.')));
        }
    }

    public static function taken(string $field, string $value, ?int $ignoreUserId = null, ?int $ignoreTenantId = null): bool
    {
        $query = User::query()->withTrashed();

        if ($field === self::EMAIL) {
            $query->whereRaw('lower(email) = ?', [Str::lower(trim($value))]);
        } else {
            $values = array_values(array_unique(array_filter([trim($value), PhoneNumber::normalize($value)])));
            $query->whereIn('phone', $values);
        }

        // A deleted account only counts inside its own customer, where the
        // database still holds its email / phone.
        $currentTenantId = app(TenantContext::class)->id();
        $query->where(fn ($q) => $q->whereNull('deleted_at')->when($currentTenantId !== null, fn ($q) => $q->orWhere('tenant_id', $currentTenantId)));

        return $query
            ->when($ignoreUserId !== null, fn ($q) => $q->whereKeyNot($ignoreUserId))
            ->when($ignoreTenantId !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', '!=', $ignoreTenantId)))
            ->exists();
    }
}
