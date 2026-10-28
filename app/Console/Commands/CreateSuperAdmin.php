<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-super-admin')]
#[Description('Create the initial Super Admin account interactively.')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        if (User::query()->where('role', 'super_admin')->exists()) {
            $this->components->error('A Super Admin account already exists.');

            return self::FAILURE;
        }

        $validated = Validator::make([
            'name' => $this->ask('Name'),
            'email' => $this->ask('Email'),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ])->validate();

        User::create([
            'name' => trim($validated['name']),
            'email' => Str::lower(trim($validated['email'])),
            'password' => $validated['password'],
            'role' => 'super_admin',
        ])->forceFill(['email_verified_at' => now()])->save();

        $this->components->info('Super Admin created. Sign in with the email and password entered above.');

        return self::SUCCESS;
    }
}
