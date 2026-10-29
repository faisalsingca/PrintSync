<?php

use App\Models\AuditLog;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

test('invitee can accept the signed invitation and go to the correct dashboard', function (string $role, string $dashboardRoute) {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $token = str_repeat('b', 64);
    $invitation = StaffInvitation::create([
        'name' => 'Jamie Printer',
        'email' => 'jamie@example.com',
        'role' => $role,
        'created_by' => $superAdmin->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'email_sent_at' => now(),
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'staff-invitations.show',
        now()->addDays(7),
        ['token' => $token],
    );

    $this->get($signedUrl)
        ->assertOk()
        ->assertSee('Confirm invitation')
        ->assertSee('jamie@example.com');

    $response = $this->post(route('staff-invitations.accept', ['token' => $token]), [
        'password' => 'SecurePass#1234',
        'password_confirmation' => 'SecurePass#1234',
    ]);

    $user = User::query()->where('email', 'jamie@example.com')->firstOrFail();

    $response->assertRedirect(route($dashboardRoute));
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseMissing('staff_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $superAdmin->id,
        'actor_role' => 'super_admin',
        'action' => 'admin.staff_created',
        'subject_id' => $user->id,
    ]);

    expect($user->role)->toBe($role)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('SecurePass#1234', $user->password))->toBeTrue();

    $this->post(route('staff-invitations.accept', ['token' => $token]), [
        'password' => 'SecurePass#1234',
        'password_confirmation' => 'SecurePass#1234',
    ])->assertNotFound();

    expect(User::query()->where('email', 'jamie@example.com')->count())->toBe(1)
        ->and(AuditLog::query()->where('subject_id', $user->id)->count())->toBe(1);
})->with([
    'staff' => ['staff', 'staff.dashboard'],
    'cashier' => ['cashier', 'cashier.dashboard'],
]);

test('expired invitation cannot create an employee account', function () {
    $token = str_repeat('c', 64);
    StaffInvitation::create([
        'name' => 'Jamie Printer',
        'email' => 'jamie@example.com',
        'role' => 'staff',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subMinute(),
    ]);

    $this->post(route('staff-invitations.accept', ['token' => $token]), [
        'password' => 'SecurePass#1234',
        'password_confirmation' => 'SecurePass#1234',
    ])->assertNotFound();

    expect(User::query()->where('email', 'jamie@example.com')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'admin.staff_created')->exists())->toBeFalse();
});

test('invalid password leaves the invitation pending and does not create an account', function () {
    $token = str_repeat('f', 64);
    $invitation = StaffInvitation::create([
        'name' => 'Jamie Printer',
        'email' => 'jamie@example.com',
        'role' => 'staff',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('staff-invitations.accept', ['token' => $token]), [
        'password' => 'SecurePass#1234',
        'password_confirmation' => 'DifferentPass#1234',
    ])->assertSessionHasErrors('password');

    $this->assertDatabaseHas('staff_invitations', ['id' => $invitation->id]);
    expect(User::query()->where('email', 'jamie@example.com')->exists())->toBeFalse();
});

test('invitation page requires a valid signed link and token', function () {
    $token = str_repeat('d', 64);
    StaffInvitation::create([
        'name' => 'Jamie Printer',
        'email' => 'jamie@example.com',
        'role' => 'staff',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('staff-invitations.show', ['token' => $token]))
        ->assertForbidden();

    $invalidTokenUrl = URL::temporarySignedRoute(
        'staff-invitations.show',
        now()->addDays(7),
        ['token' => str_repeat('e', 64)],
    );

    $this->get($invalidTokenUrl)->assertNotFound();
});

test('invitation email safely escapes the invitee name', function () {
    $notification = new StaffAccountInvitation(
        '<script>alert(1)</script>',
        'staff',
        'https://example.test/staff-invitations/token',
    );

    $html = (string) $notification->toMail((object) [])->render();

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});
