<?php

use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\VerifyPendingEmail;
use App\Rules\EmailDomainHasMailRoute;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    app()->instance(EmailDomainHasMailRoute::class, new EmailDomainHasMailRoute(
        static fn (string $domain, string $type): bool => $type === 'A',
    ));
});

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();
    $currentEmail = $user->email;
    $currentVerifiedAt = $user->email_verified_at;

    Notification::fake();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->assertSee('Confirm new email')
        ->assertSee('Send verification links')
        ->set('emailConfirmation', 'test@example.com')
        ->call('updateProfileInformation');

    $response
        ->assertHasNoErrors()
        ->assertSee('Cancel email change')
        ->assertSee('dark:bg-amber-950/40', false);

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual($currentEmail);
    expect($user->email_verified_at->equalTo($currentVerifiedAt))->toBeTrue();
    $this->assertDatabaseHas('email_change_requests', [
        'user_id' => $user->id,
        'old_email' => $currentEmail,
        'new_email' => 'test@example.com',
        'old_approved_at' => null,
        'new_verified_at' => null,
    ]);

    Notification::assertSentOnDemand(ApproveEmailChange::class, function ($notification, array $channels, $notifiable) use ($currentEmail): bool {
        return $notifiable->routes['mail'] === $currentEmail
            && $notification->newEmail === 'test@example.com';
    });

    Notification::assertSentOnDemand(VerifyPendingEmail::class, function ($notification, array $channels, $notifiable): bool {
        return $notifiable->routes['mail'] === 'test@example.com';
    });
});

test('email changes with no MX or A DNS records are rejected without sending notifications', function () {
    $user = User::factory()->create(['email' => 'current@example.com']);
    $dnsLookups = [];

    app()->instance(EmailDomainHasMailRoute::class, new EmailDomainHasMailRoute(
        static function (string $domain, string $type) use (&$dnsLookups): bool {
            $dnsLookups[] = [$domain, $type];

            return false;
        },
    ));
    Notification::fake();

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'user@gmial.con')
        ->set('emailConfirmation', 'user@gmial.con')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email'])
        ->assertSee('This email address is not valid. Please check for typos.');

    expect($user->fresh()->email)->toBe('current@example.com');
    expect($dnsLookups)->toBe([
        ['gmial.con', 'MX'],
        ['gmial.con', 'A'],
    ]);
    $this->assertDatabaseMissing('email_change_requests', ['user_id' => $user->id]);
    Notification::assertNothingSent();
});

test('email changes are rejected until the new address is confirmed in the form', function (?string $confirmation, string $rule, string $message) {
    $user = User::factory()->create(['email' => 'current@example.com']);

    Notification::fake([ApproveEmailChange::class, VerifyPendingEmail::class]);

    $profile = Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'replacement@example.com');

    if ($confirmation !== null) {
        $profile->set('emailConfirmation', $confirmation);
    }

    $profile
        ->call('updateProfileInformation')
        ->assertHasErrors(['emailConfirmation' => $rule])
        ->assertSee($message);

    expect($user->fresh()->email)->toBe('current@example.com');
    $this->assertDatabaseMissing('email_change_requests', ['user_id' => $user->id]);
    Notification::assertSentOnDemandTimes(ApproveEmailChange::class, 0);
    Notification::assertSentOnDemandTimes(VerifyPendingEmail::class, 0);
})->with([
    'confirmation missing' => [null, 'required', 'Please confirm the new email address.'],
    'confirmation does not match' => ['replacement-typo@example.com', 'same', 'The email addresses do not match.'],
]);

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
