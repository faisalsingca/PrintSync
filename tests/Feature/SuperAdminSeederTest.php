<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Hash;

test('database seeding creates the configured initial super admin', function () {
    config()->set('app.initial_super_admin', [
        'name' => 'Initial Administrator',
        'email' => 'initial-admin@example.com',
        'password' => 'InitialAdmin#1234',
    ]);

    $this->seed();

    $superAdmin = User::query()->where('email', 'initial-admin@example.com')->firstOrFail();

    expect($superAdmin->role)->toBe('super_admin')
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('InitialAdmin#1234', $superAdmin->password))->toBeTrue();
});

test('repeated seeding does not replace an existing super admin password', function () {
    config()->set('app.initial_super_admin', [
        'name' => 'Initial Administrator',
        'email' => 'initial-admin@example.com',
        'password' => 'InitialAdmin#1234',
    ]);

    $this->seed(SuperAdminSeeder::class);
    $superAdmin = User::query()->where('email', 'initial-admin@example.com')->firstOrFail();
    $superAdmin->update(['password' => 'ChangedPassword#5678']);

    config()->set('app.initial_super_admin.password', 'AnotherPassword#9012');
    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('role', 'super_admin')->count())->toBe(1)
        ->and(Hash::check('ChangedPassword#5678', $superAdmin->fresh()->password))->toBeTrue();
});

test('seeding without super admin settings does not create an account', function () {
    config()->set('app.initial_super_admin', [
        'name' => null,
        'email' => null,
        'password' => null,
    ]);

    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('role', 'super_admin')->exists())->toBeFalse();
});

test('seeding fails when the configured email belongs to another account', function () {
    User::factory()->create([
        'email' => 'initial-admin@example.com',
        'role' => 'customer',
    ]);

    config()->set('app.initial_super_admin', [
        'name' => 'Initial Administrator',
        'email' => 'initial-admin@example.com',
        'password' => 'InitialAdmin#1234',
    ]);

    expect(fn () => $this->seed(SuperAdminSeeder::class))
        ->toThrow(RuntimeException::class, 'The configured Super Admin email is already in use by another account.');

    expect(User::query()->where('role', 'super_admin')->exists())->toBeFalse();
});
