<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
        'recovery_enabled' => false,
    ]);

    $this->seed(SuperAdminSeeder::class);
    $superAdmin = User::query()->where('email', 'initial-admin@example.com')->firstOrFail();
    $superAdmin->update(['password' => 'ChangedPassword#5678']);

    config()->set('app.initial_super_admin.password', 'AnotherPassword#9012');
    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('role', 'super_admin')->count())->toBe(1)
        ->and(Hash::check('ChangedPassword#5678', $superAdmin->fresh()->password))->toBeTrue();
});

test('explicit recovery updates credentials accepted by the login flow', function () {
    $superAdmin = User::factory()->create([
        'name' => 'Old Administrator',
        'email' => 'old-admin@example.invalid',
        'role' => 'super_admin',
        'email_verified_at' => null,
        'is_active' => false,
        'password' => 'OldPassword#1234',
    ]);

    config()->set('app.initial_super_admin', [
        'name' => 'Recovered Administrator',
        'email' => 'recovered-admin@example.com',
        'password' => 'RecoveredAdmin#5678',
        'recovery_enabled' => true,
    ]);

    $this->seed(SuperAdminSeeder::class);

    $superAdmin->refresh();
    config(['otp.enabled' => true]);
    Mail::fake();

    expect(User::query()->where('role', 'super_admin')->count())->toBe(1)
        ->and($superAdmin->name)->toBe('Recovered Administrator')
        ->and($superAdmin->email)->toBe('recovered-admin@example.com')
        ->and($superAdmin->is_active)->toBeTrue()
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('RecoveredAdmin#5678', $superAdmin->password))->toBeTrue();

    $this->post(route('login.store'), [
        'email' => 'recovered-admin@example.com',
        'password' => 'RecoveredAdmin#5678',
    ])->assertRedirect(route('otp.show'));
});

test('explicit recovery leaves the super admin unchanged when the configured email belongs to another account', function () {
    $superAdmin = User::factory()->create([
        'email' => 'old-admin@example.invalid',
        'role' => 'super_admin',
        'password' => 'OldPassword#1234',
    ]);
    User::factory()->create([
        'email' => 'in-use@example.com',
        'role' => 'customer',
    ]);

    config()->set('app.initial_super_admin', [
        'name' => 'Recovered Administrator',
        'email' => 'in-use@example.com',
        'password' => 'RecoveredAdmin#5678',
        'recovery_enabled' => true,
    ]);

    $this->seed(SuperAdminSeeder::class);

    expect($superAdmin->fresh()->email)->toBe('old-admin@example.invalid')
        ->and(Hash::check('OldPassword#1234', $superAdmin->fresh()->password))->toBeTrue();
});

test('explicit recovery leaves multiple super admin accounts unchanged', function () {
    User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['role' => 'super_admin']);

    config()->set('app.initial_super_admin', [
        'name' => 'Recovered Administrator',
        'email' => 'recovered-admin@example.com',
        'password' => 'RecoveredAdmin#5678',
        'recovery_enabled' => true,
    ]);

    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('role', 'super_admin')->count())->toBe(2)
        ->and(User::query()->where('email', 'recovered-admin@example.com')->exists())->toBeFalse();
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
