<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $initialSuperAdmin = config('app.initial_super_admin');
        $name = is_array($initialSuperAdmin) ? ($initialSuperAdmin['name'] ?? null) : null;
        $email = is_array($initialSuperAdmin) ? ($initialSuperAdmin['email'] ?? null) : null;
        $password = is_array($initialSuperAdmin) ? ($initialSuperAdmin['password'] ?? null) : null;
        $recoveryEnabled = is_array($initialSuperAdmin) && ($initialSuperAdmin['recovery_enabled'] ?? false);

        $superAdmins = User::query()->where('role', 'super_admin')->orderBy('id')->get();

        if ($superAdmins->isNotEmpty() && ! $recoveryEnabled) {
            if (isset($this->command)) {
                $this->command->info('A Super Admin account already exists; skipping creation.');
            }

            return;
        }

        if (! $recoveryEnabled && blank($name) && blank($email) && blank($password)) {
            if (isset($this->command)) {
                $this->command->warn('Super Admin was not created; configure SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL, and SUPER_ADMIN_PASSWORD first.');
            }

            return;
        }

        if ($recoveryEnabled && $superAdmins->count() > 1) {
            if (isset($this->command)) {
                $this->command->error('Super Admin recovery requires exactly one existing Super Admin account; no accounts were changed.');
            }

            return;
        }

        $validator = Validator::make([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::defaults()],
        ]);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $exception) {
            if (! $recoveryEnabled) {
                throw $exception;
            }

            if (isset($this->command)) {
                $this->command->error('Super Admin recovery settings are invalid: '.$exception->getMessage());
            }

            return;
        }

        $superAdmin = $superAdmins->first();

        $emailIsInUse = User::query()
            ->where('email', $validated['email'])
            ->when($superAdmin !== null, fn ($query) => $query->whereKeyNot($superAdmin->getKey()))
            ->exists();

        if ($emailIsInUse && $recoveryEnabled) {
            if (isset($this->command)) {
                $this->command->error('The configured Super Admin recovery email is already in use by another account; no accounts were changed.');
            }

            return;
        }

        if ($emailIsInUse) {
            throw new RuntimeException('The configured Super Admin email is already in use by another account.');
        }

        if ($superAdmin !== null) {
            $superAdmin->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'email_verified_at' => $superAdmin->email_verified_at ?? now(),
                'is_active' => true,
            ])->save();

            if (isset($this->command)) {
                $this->command->info('The existing Super Admin account was updated from the configured recovery settings.');
            }

            return;
        }

        $superAdmin = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'super_admin',
        ]);

        $superAdmin->forceFill(['email_verified_at' => now()])->save();

        if (isset($this->command)) {
            $this->command->info('Super Admin account created from the configured seed settings.');
        }
    }
}
