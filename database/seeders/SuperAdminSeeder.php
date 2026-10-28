<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (User::query()->where('role', 'super_admin')->exists()) {
            if (isset($this->command)) {
                $this->command->info('A Super Admin account already exists; skipping creation.');
            }

            return;
        }

        $initialSuperAdmin = config('app.initial_super_admin');
        $name = is_array($initialSuperAdmin) ? ($initialSuperAdmin['name'] ?? null) : null;
        $email = is_array($initialSuperAdmin) ? ($initialSuperAdmin['email'] ?? null) : null;
        $password = is_array($initialSuperAdmin) ? ($initialSuperAdmin['password'] ?? null) : null;

        if (blank($name) && blank($email) && blank($password)) {
            if (isset($this->command)) {
                $this->command->warn('Super Admin was not created; configure SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL, and SUPER_ADMIN_PASSWORD first.');
            }

            return;
        }

        $validated = Validator::make([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::defaults()],
        ])->validate();

        if (User::query()->where('email', $validated['email'])->exists()) {
            throw new RuntimeException('The configured Super Admin email is already in use by another account.');
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
