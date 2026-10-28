<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('super admin can create employee accounts with a password setup email', function () {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->set('name', 'Jamie Printer')
        ->set('email', 'jamie@example.com')
        ->set('role', 'cashier')
        ->call('createStaffAccount')
        ->assertHasNoErrors();

    $staffAccount = User::query()->where('email', 'jamie@example.com')->firstOrFail();

    expect($staffAccount->role)->toBe('cashier')
        ->and($staffAccount->is_active)->toBeTrue();

    Notification::assertSentTo($staffAccount, ResetPassword::class);
    $this->assertDatabaseHas('audit_logs', [
        'actor_role' => 'super_admin',
        'action' => 'admin.staff_created',
        'subject_id' => $staffAccount->id,
    ]);
});

test('super admin can deactivate and reactivate staff without removing history', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $staffAccount = User::factory()->create(['role' => 'staff']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->call('toggleStaffAccount', $staffAccount->id)
        ->assertSet('statusMessage', "{$staffAccount->name}'s account was deactivated.")
        ->call('toggleStaffAccount', $staffAccount->id)
        ->assertSet('statusMessage', "{$staffAccount->name}'s account was activated.");

    expect($staffAccount->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('subject_id', $staffAccount->id)->count())->toBe(2);
});

test('only super admin can manage employee accounts', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('super-admin.staff'))
        ->assertForbidden();
})->with(['customer', 'staff', 'cashier', 'owner']);

test('deactivated accounts are logged out of protected pages', function () {
    $staffAccount = User::factory()->create([
        'role' => 'staff',
        'is_active' => false,
    ]);

    $this->actingAs($staffAccount)
        ->get(route('staff.dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the initial super admin can be provisioned interactively', function () {
    $this->artisan('app:create-super-admin')
        ->expectsQuestion('Name', 'Initial Administrator')
        ->expectsQuestion('Email', 'initial-admin@example.com')
        ->expectsQuestion('Password', 'SecurePass#1234')
        ->expectsQuestion('Confirm password', 'SecurePass#1234')
        ->assertSuccessful();

    expect(User::query()->where('email', 'initial-admin@example.com')->value('role'))
        ->toBe('super_admin');
});
