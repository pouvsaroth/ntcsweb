<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The one place a platform super admin gets created.
 *
 * There is deliberately no HTTP endpoint for this: minting the account with
 * tenant_id = NULL and the super-admin role is the single most powerful
 * action in the system, and it always requires shell access to the server.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'admin:create-super-admin
        {--name= : Full name}
        {--email= : Email address}
        {--password= : Password (prompted securely if omitted)}';

    protected $description = 'Create a platform super admin account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password');

        try {
            $email = trim(filter_var($email, FILTER_VALIDATE_EMAIL) ?: '');

            if ($email === '') {
                throw ValidationException::withMessages(['email' => 'A valid email address is required.']);
            }

            if (User::query()->whereNull('tenant_id')->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'A platform account with this email already exists.']);
            }

            $rules = Password::defaults();
            validator(['password' => $password], ['password' => ['required', $rules]])->validate();
        } catch (ValidationException $e) {
            $this->components->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $role = Role::query()->platform()->where('slug', Role::SUPER_ADMIN)->first();

        if ($role === null) {
            $this->components->error(
                'The platform super-admin role does not exist yet. Run "php artisan db:seed --class=RolePermissionSeeder" first.'
            );

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $role) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
            ]);

            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            $user->attachRoles($role);
        });

        $this->components->info("Super admin \"{$email}\" created.");

        return self::SUCCESS;
    }
}
