<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('super admin can create employee accounts with a password setup email', function () {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->call('openCreateStaffModal')
        ->assertSet('showCreateStaffModal', true)
        ->set('name', 'Jamie Printer')
        ->set('email', 'jamie@example.com')
        ->set('role', 'cashier')
        ->call('createStaffAccount')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', 'Account created. Password setup and email verification links were sent to jamie@example.com.')
        ->assertSet('showCreateStaffModal', false);

    $staffAccount = User::query()->where('email', 'jamie@example.com')->firstOrFail();

    expect($staffAccount->role)->toBe('cashier')
        ->and($staffAccount->is_active)->toBeTrue();

    Notification::assertSentTo($staffAccount, ResetPassword::class);
    Notification::assertSentTo($staffAccount, VerifyEmail::class);
    $this->assertDatabaseHas('audit_logs', [
        'actor_role' => 'super_admin',
        'action' => 'admin.staff_created',
        'subject_id' => $staffAccount->id,
    ]);
});

test('super admin must confirm staff status changes without removing history', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $staffAccount = User::factory()->create(['role' => 'staff']);

    $this->actingAs($superAdmin);

    $component = Livewire::test('pages::super-admin.staff-accounts');

    $component
        ->call('requestStaffAccountStatusChange', $staffAccount->id)
        ->assertSet('showStaffStatusConfirmationModal', true)
        ->assertSet('pendingStaffAccountId', $staffAccount->id);

    expect($staffAccount->fresh()->is_active)->toBeTrue();

    $component
        ->call('confirmStaffAccountStatusChange')
        ->assertSet('statusMessage', "{$staffAccount->name}'s account was deactivated.")
        ->assertSet('showStaffStatusConfirmationModal', false)
        ->call('requestStaffAccountStatusChange', $staffAccount->id)
        ->call('confirmStaffAccountStatusChange')
        ->assertSet('statusMessage', "{$staffAccount->name}'s account was activated.");

    expect($staffAccount->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('subject_id', $staffAccount->id)->count())->toBe(2);
});

test('staff account actions use modals instead of browser confirmation dialogs', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->assertSee('Create staff account')
        ->assertSee('Create employee account')
        ->assertSee('Change staff account status?')
        ->assertDontSee('wire:confirm', false);
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

test('unverified staff and cashier accounts are redirected to verify their email', function (string $role, string $dashboardRoute) {
    $account = User::factory()->unverified()->create(['role' => $role]);

    $this->actingAs($account)
        ->get(route($dashboardRoute))
        ->assertRedirect(route('verification.notice'));
})->with([
    ['staff', 'staff.dashboard'],
    ['cashier', 'cashier.dashboard'],
]);

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
