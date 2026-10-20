<?php

declare(strict_types=1);

namespace App\Platform\Signup;

use App\Models\Tenant;
use App\Platform\Billing\Payments;
use App\Platform\Billing\Subscriptions;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Models\Signup;
use App\Platform\Services\SchoolCreator;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Support\PlatformAudit;
use App\Support\Billing\Khqr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service sign-up from the website (docs/backoffice/stage-8-signup.md).
 *
 * A free trial becomes a school at once, through the same SchoolCreator as
 * back office → Schools → Create. A paid plan is kept `pending_payment`,
 * holding its web address for HOLD_MINUTES, until its payment (8c).
 */
final class Signups
{
    /** The same rule as back office → Schools → Create: 3–32 characters. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/';

    public function __construct(
        private readonly SchoolCreator $creator,
        private readonly SchoolDatabase $database,
        private readonly PlatformAudit $audit,
        private readonly Bakong $bakong,
        private readonly Subscriptions $subscriptions,
        private readonly Payments $payments,
    ) {}

    /** How often one sign-up may ask Bakong (the visitor's page asks every few seconds). */
    public const CHECK_EVERY_SECONDS = 4;

    /** Why a web address cannot be used, or null when it is free. */
    public function slugProblem(string $slug, ?string $email = null): ?string
    {
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            return __('Use 3 to 32 lowercase letters, numbers or dashes.');
        }
        if (in_array($slug, config('platform.reserved_slugs', []), true)
            || Tenant::query()->withTrashed()->where('slug', $slug)->exists()
            || $this->database->exists($this->database->nameFor($slug))) {
            return __('This web address is already taken.');
        }
        $held = Signup::query()->holding()->where('slug', $slug)
            ->when($email !== null, fn ($q) => $q->where('owner_email', '!=', Str::lower($email)))
            ->exists();

        return $held ? __('This web address is already taken.') : null;
    }

    /**
     * Save a sign-up; a free trial's school is created at once.
     *
     * @param  array{plan: string, periods?: int|null, school_name: string, school_name_en?: string|null, slug: string,
     *               owner_name: string, owner_email: string, owner_phone: string, password: string, locale?: string|null}  $data
     */
    public function start(array $data, ?string $ip): Signup
    {
        $email = Str::lower(trim($data['owner_email']));
        $trial = $data['plan'] === Signup::KIND_TRIAL;
        // The system it is for (stage 9c); none = the school system.
        $product = Product::query()->where('code', (string) ($data['system'] ?? Product::SCHOOL))->where('status', Product::STATUS_ACTIVE)->first()
            ?? throw ValidationException::withMessages(['plan' => __('This system is not available.')]);

        if (($problem = $this->slugProblem($data['slug'], $email)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        $plan = null;
        $periods = null;
        if (! $trial) {
            $plan = Plan::query()->where('code', $data['plan'])->where('product_id', $product->id)->where('show_on_website', true)
                ->where('status', Plan::STATUS_ACTIVE)->where('code', '!=', Plan::LEGACY)->first()
                ?? throw ValidationException::withMessages(['plan' => __('This plan is not available.')]);
            // A one-time plan is bought once.
            $periods = $plan->period_months === null ? 1 : (int) ($data['periods'] ?? 1);
        } elseif ($product->freeTrialPlan() === null) {
            throw ValidationException::withMessages(['plan' => __('This system has no free trial. Please choose a plan.')]);
        } elseif (Signup::query()->where('kind', Signup::KIND_TRIAL)->where('product_id', $product->id)->where('owner_email', $email)->where('status', Signup::CREATED)->exists()) {
            throw ValidationException::withMessages(['owner_email' => __('This email has already had a free trial. Please choose a plan.')]);
        }

        $signup = DB::connection((new Signup)->getConnectionName())->transaction(function () use ($data, $email, $trial, $plan, $periods, $ip, $product) {
            // Starting again replaces this person's earlier unpaid sign-up.
            Signup::query()->where('owner_email', $email)->where('status', Signup::PENDING_PAYMENT)->update(['status' => Signup::EXPIRED]);

            $signup = Signup::query()->create([
                'token' => (string) Str::uuid(),
                'kind' => $trial ? Signup::KIND_TRIAL : Signup::KIND_PAID,
                'product_id' => $product->getKey(),
                'plan_id' => $plan?->getKey(),
                'periods' => $periods,
                'amount' => $plan ? round((float) $plan->price * $periods, 2) : 0,
                'currency' => $plan?->currency ?? 'USD',
                'school_name' => trim($data['school_name']),
                'school_name_en' => isset($data['school_name_en']) ? (trim($data['school_name_en']) ?: null) : null,
                'slug' => $data['slug'],
                'owner_name' => trim($data['owner_name']),
                'owner_email' => $email,
                'owner_phone' => trim($data['owner_phone']),
                'owner_password' => Hash::make($data['password']),
                'locale' => in_array($data['locale'] ?? null, ['en', 'km'], true) ? $data['locale'] : 'km',
                'status' => Signup::PENDING_PAYMENT,
                'expires_at' => now()->addMinutes(Signup::HOLD_MINUTES),
                'ip_address' => $ip,
            ]);
            if (! $trial) {
                $this->attachKhqr($signup);
            }
            $this->audit->record('signup.started', $signup, new: [
                'kind' => $signup->kind, 'plan' => $plan?->code, 'periods' => $periods, 'slug' => $signup->slug, 'email' => $email,
            ]);

            return $signup;
        });

        if (! $trial) {
            return $signup;
        }
        // The school system's trial as always; another system's on its own trial plan.
        $trialPlan = $product->freeTrialPlan();

        return $this->createSchool($signup, $product->code === Product::SCHOOL ? null : fn (Tenant $tenant) => $this->subscriptions->subscribe(
            $tenant, $trialPlan, SchoolSubscription::TRIAL, SchoolSubscription::today(), null, __('Free trial from the website.'), null,
        ));
    }

    /** A fixed-amount KHQR for this sign-up from NewTech's own code, valid until its hold ends. Left empty while none is set. */
    private function attachKhqr(Signup $signup): void
    {
        $template = BillingSettings::current()->khqr_template;
        if (blank($template)) {
            return;
        }
        try {
            $code = Khqr::forPayment($template, (float) $signup->amount, $signup->currency, $signup->created_at ?? now(), $signup->expires_at);
        } catch (\InvalidArgumentException $e) {
            report($e);

            return;
        }
        $signup->forceFill(['khqr' => $code, 'khqr_md5' => md5($code)])->save();
    }

    /**
     * Has this sign-up been paid? Asks Bakong (at most every CHECK_EVERY_SECONDS);
     * paid → the school is created on its plan with the payment recorded.
     * Asked by the visitor's page and by the every-minute tick, until a few
     * minutes after the code expired.
     */
    public function checkPayment(Signup $signup, bool $force = false): Signup
    {
        $late = (int) config('platform.bakong.late_minutes', 10);
        if ($signup->status !== Signup::PENDING_PAYMENT || $signup->khqr_md5 === null
            || $signup->expires_at === null || $signup->expires_at->copy()->addMinutes($late)->isPast()) {
            return $signup;
        }

        $found = DB::connection($signup->getConnectionName())->transaction(function () use ($signup, $force) {
            $fresh = Signup::query()->lockForUpdate()->findOrFail($signup->getKey());
            if ($fresh->status !== Signup::PENDING_PAYMENT
                || (! $force && $fresh->last_checked_at !== null && $fresh->last_checked_at->diffInSeconds(now()) < self::CHECK_EVERY_SECONDS)) {
                return null;
            }
            $fresh->forceFill(['last_checked_at' => now()])->save();

            $paid = $this->bakong->paymentFor($fresh->khqr_md5);
            if ($paid === null) {
                return null;
            }

            // Paid — but the amount and currency must be what was asked.
            $right = $paid['currency'] === $fresh->currency && $paid['amount'] + 0.001 >= (float) $fresh->amount;
            $fresh->forceFill([
                'status' => $right ? Signup::PAID : Signup::FAILED,
                'paid_at' => now(),
                'bakong_hash' => $paid['hash'],
                'paid_amount' => $paid['amount'],
                'paid_currency' => $paid['currency'],
                'failure' => $right ? null : "Paid {$paid['amount']} {$paid['currency']}, expected {$fresh->amount} {$fresh->currency}.",
            ])->save();
            $this->audit->record('signup.paid', $fresh, new: ['hash' => $paid['hash'], 'amount' => $paid['amount'], 'currency' => $paid['currency'], 'accepted' => $right]);

            return $fresh;
        });

        if ($found === null) {
            return $signup->fresh();
        }

        return $found->status === Signup::PAID ? $this->createPaidSchool($found) : $found;
    }

    /** A paid sign-up becomes its school: on the plan, active for the months bought, the payment recorded with a receipt. */
    public function createPaidSchool(Signup $signup): Signup
    {
        $plan = $signup->plan ?? throw new \RuntimeException('The plan of this sign-up no longer exists.');

        try {
            return $this->createSchool($signup, function (Tenant $tenant) use ($signup, $plan) {
                $today = SchoolSubscription::today();
                // Opened for today, then the payment makes it active from today for the months bought.
                $this->subscriptions->subscribe($tenant, $plan, SchoolSubscription::TRIAL, $today, $today, __('Signed up on the website.'), null);
                $payment = $this->payments->record($tenant, [
                    'periods' => max(1, (int) $signup->periods),
                    'amount' => $signup->paid_amount ?? $signup->amount,
                    'method' => 'khqr',
                    'reference' => $signup->bakong_hash,
                    'notes' => __('Paid by KHQR on the website (sign-up #:id).', ['id' => $signup->getKey()]),
                ], null);
                $signup->forceFill(['payment_id' => $payment->getKey()])->save();
            });
        } catch (\Throwable $e) {
            report($e);
            Signup::query()->whereKey($signup->getKey())->update(['status' => Signup::FAILED, 'failure' => 'Paid, but the school could not be created: '.$e->getMessage()]);

            return $signup->fresh();
        }
    }

    /** Unpaid sign-ups past their hold (and the late window) are closed. */
    public function expireStale(): int
    {
        return Signup::query()->where('status', Signup::PENDING_PAYMENT)
            ->where('expires_at', '<', now()->subMinutes((int) config('platform.bakong.late_minutes', 10)))
            ->update(['status' => Signup::EXPIRED, 'owner_password' => null]);
    }

    /**
     * The sign-up becomes a school (setup runs in the background).
     *
     * @param  (\Closure(Tenant): mixed)|null  $subscribe  Puts the school on its plan; null = the free trial.
     */
    public function createSchool(Signup $signup, ?\Closure $subscribe = null): Signup
    {
        return DB::connection($signup->getConnectionName())->transaction(function () use ($signup, $subscribe) {
            $signup = Signup::query()->lockForUpdate()->findOrFail($signup->getKey());
            if ($signup->tenant_id !== null) {
                return $signup;
            }

            $tenant = $this->creator->create(
                [
                    'name' => $signup->school_name,
                    'name_en' => $signup->school_name_en,
                    'slug' => $signup->slug,
                    'owner_name' => $signup->owner_name,
                    'owner_email' => $signup->owner_email,
                    'email' => $signup->owner_email,
                    'phone' => $signup->owner_phone,
                    'locale' => $signup->locale,
                    'default_currency' => $signup->currency,
                ],
                (string) config('platform.default_placement', SchoolPlacement::SHARED),
                null,
                $signup->owner_password,
                $subscribe,
                'school.signed_up',
            );

            $signup->refresh()->forceFill(['status' => Signup::CREATED, 'tenant_id' => $tenant->getKey(), 'owner_password' => null, 'expires_at' => null])->save();

            return $signup;
        });
    }
}
