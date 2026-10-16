<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformStaff;
use App\Platform\Support\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The first back-office Owner — the one account that cannot be created from
 * the back office, because nobody is in it yet. Needs shell access to the
 * server, on purpose.
 *
 * Prints a temporary password once. 2FA enrolment and a new password are
 * both forced at first sign-in.
 */
class CreatePlatformStaffCommand extends Command
{
    protected $signature = 'platform:create-staff
        {email : Email address}
        {name : Full name}';

    protected $description = 'Create a back-office Owner account with a temporary password';

    public function handle(PlatformAudit $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('A valid email address is required.');

            return self::FAILURE;
        }

        if (PlatformStaff::query()->where('email', $email)->exists()) {
            $this->components->error("A back-office account for {$email} already exists.");

            return self::FAILURE;
        }

        $owner = PlatformRole::query()->where('is_owner', true)->first();
        if ($owner === null) {
            $this->components->error('The Owner role is missing. Run the migrations first.');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);

        DB::connection($owner->getConnectionName())->transaction(function () use ($email, $password, $owner, $audit) {
            $staff = new PlatformStaff(['name' => (string) $this->argument('name'), 'email' => $email]);
            $staff->forceFill([
                'password' => $password,
                'status' => PlatformStaff::STATUS_ACTIVE,
                'must_change_password' => true,
            ])->save();
            $staff->roles()->attach($owner);

            $audit->record('staff.created', $staff, new: ['email' => $email, 'via' => 'cli', 'roles' => [$owner->id]]);
        });

        $this->components->info("Owner {$email} created.");
        $this->line("  Temporary password: <comment>{$password}</comment>");
        $this->line('  Shown once. Sign-in will ask for a new password and to set up 2FA.');

        return self::SUCCESS;
    }
}
