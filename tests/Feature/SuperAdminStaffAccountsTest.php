<?php

use App\Models\AuditLog;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;

test('super admin can send an invitation without creating the employee account', function () {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->call('openCreateStaffModal')
        ->assertSet('showCreateStaffModal', true)
        ->set('name', 'Jamie Printer')
        ->set('email', 'Jamie@Example.com')
        ->set('role', 'cashier')
        ->call('createStaffAccount')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', 'Invitation sent to jamie@example.com. The account will be created after they confirm and choose a password.')
        ->assertSet('showCreateStaffModal', false);

    $invitation = StaffInvitation::query()->where('email', 'jamie@example.com')->firstOrFail();

    expect($invitation->role)->toBe('cashier')
        ->and($invitation->created_by)->toBe($superAdmin->id)
        ->and($invitation->email_sent_at)->not->toBeNull()
        ->and(User::query()->where('email', 'jamie@example.com')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'admin.staff_created')->exists())->toBeFalse();

    Notification::assertSentOnDemand(
        StaffAccountInvitation::class,
        fn ($notification, array $channels, $notifiable): bool => $channels === ['mail']
            && $notifiable->routes['mail'] === 'jamie@example.com',
    );
    Livewire::test('pages::super-admin.staff-accounts')
        ->assertSee('Pending invitations')
        ->assertSee('Waiting for confirmation')
        ->assertSee('jamie@example.com');
});

test('super admin cannot invite an email that already has an account', function () {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['email' => 'jamie@example.com']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->set('name', 'Jamie Printer')
        ->set('email', 'jamie@example.com')
        ->set('role', 'staff')
        ->call('createStaffAccount')
        ->assertHasErrors(['email']);

    expect(StaffInvitation::query()->where('email', 'jamie@example.com')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

test('super admin sees a saved invitation when its email could not be sent', function () {
    Notification::shouldReceive('send')
        ->once()
        ->andThrow(new TransportException('Mail service unavailable.'));

    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->set('name', 'Jamie Printer')
        ->set('email', 'jamie@example.com')
        ->set('role', 'staff')
        ->call('createStaffAccount')
        ->assertSet('statusMessageIsError', true)
        ->assertSee('Email not sent');

    expect(StaffInvitation::query()->where('email', 'jamie@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'jamie@example.com')->exists())->toBeFalse();
});

test('super admin can resend a pending invitation', function () {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $oldToken = str_repeat('a', 64);
    $invitation = StaffInvitation::create([
        'name' => 'Jamie Printer',
        'email' => 'jamie@example.com',
        'role' => 'staff',
        'created_by' => $superAdmin->id,
        'token_hash' => hash('sha256', $oldToken),
        'expires_at' => now()->subDay(),
    ]);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.staff-accounts')
        ->call('resendStaffInvitation', $invitation->id)
        ->assertSet('statusMessage', 'A new invitation was sent to jamie@example.com.');

    expect($invitation->fresh()->token_hash)->not->toBe(hash('sha256', $oldToken))
        ->and($invitation->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($invitation->fresh()->email_sent_at)->not->toBeNull();

    Notification::assertSentOnDemand(StaffAccountInvitation::class);
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
