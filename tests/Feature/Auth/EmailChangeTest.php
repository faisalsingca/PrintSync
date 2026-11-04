<?php

use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\EmailAddressChanged;
use App\Notifications\VerifyPendingEmail;
use App\Rules\EmailDomainHasMailRoute;
use Illuminate\Auth\Events\Verified;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    app()->instance(EmailDomainHasMailRoute::class, new EmailDomainHasMailRoute(
        static fn (string $domain, string $type): bool => $type === 'A',
    ));
});

test('a verified email change requires approval of the old address and verification of the new one', function () {
    $user = User::factory()->create(['email' => 'current@example.com']);
    $originalVerifiedAt = $user->email_verified_at;

    Notification::fake([
        ApproveEmailChange::class,
        VerifyPendingEmail::class,
        EmailAddressChanged::class,
    ]);
    Event::fake([Verified::class]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'replacement@example.com')
        ->set('emailConfirmation', 'replacement@example.com')
        ->set('phone', $user->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $approvalUrl = null;
    Notification::assertSentOnDemand(
        ApproveEmailChange::class,
        function (ApproveEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use (&$approvalUrl): bool {
            $approvalUrl = $notification->url;

            return $notifiable->routes['mail'] === 'current@example.com';
        },
    );

    $verificationUrl = null;
    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        function (VerifyPendingEmail $notification, array $channels, AnonymousNotifiable $notifiable) use (&$verificationUrl): bool {
            $verificationUrl = $notification->url;

            return $notifiable->routes['mail'] === 'replacement@example.com';
        },
    );

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'current@example.com',
        'email_verified_at' => $originalVerifiedAt,
    ]);
    $this->assertDatabaseHas('email_change_requests', [
        'user_id' => $user->id,
        'old_email' => 'current@example.com',
        'new_email' => 'replacement@example.com',
        'old_approved_at' => null,
        'new_verified_at' => null,
    ]);

    $approvalPath = (string) parse_url($approvalUrl, PHP_URL_PATH);
    $this->get($approvalPath)->assertOk();
    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('old_approved_at'))->toBeNull();
    $this->post($approvalPath)->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email)->toBe('current@example.com');
    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('old_approved_at'))->not->toBeNull();

    $verificationPath = (string) parse_url($verificationUrl, PHP_URL_PATH);
    $this->get($verificationPath)->assertOk();
    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('new_verified_at'))->toBeNull();
    $this->post($verificationPath)->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email)->toBe('replacement@example.com');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseMissing('email_change_requests', ['user_id' => $user->id]);
    Event::assertDispatched(Verified::class);
    Notification::assertSentOnDemand(
        EmailAddressChanged::class,
        fn (EmailAddressChanged $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'current@example.com'
            && $notification->newEmail === 'replacement@example.com',
    );
});

test('an unverified current address does not need to approve its replacement', function () {
    $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    Notification::fake([
        ApproveEmailChange::class,
        VerifyPendingEmail::class,
        EmailAddressChanged::class,
    ]);

    $this->actingAs($user);

    $profile = Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'corrected@example.com')
        ->set('emailConfirmation', 'corrected@example.com')
        ->set('phone', $user->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $profile->assertSee('Confirmation links expire in one hour.');
    $profile->assertDontSee('Click here to re-send the verification email.');

    Notification::assertSentOnDemandTimes(ApproveEmailChange::class, 0);

    $verificationUrl = null;
    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        function (VerifyPendingEmail $notification, array $channels, AnonymousNotifiable $notifiable) use (&$verificationUrl): bool {
            $verificationUrl = $notification->url;

            return $notifiable->routes['mail'] === 'corrected@example.com';
        },
    );

    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('old_approved_at'))->not->toBeNull();
    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('new_verified_at'))->toBeNull();
    expect($user->fresh()->email)->toBe('unverified@example.com');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $verificationPath = (string) parse_url($verificationUrl, PHP_URL_PATH);
    $this->get($verificationPath)->assertOk();
    expect(EmailChangeRequest::query()->where('user_id', $user->id)->value('new_verified_at'))->toBeNull();
    $this->post($verificationPath)->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email)->toBe('corrected@example.com');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentOnDemandTimes(EmailAddressChanged::class, 0);
});

test('an invalid email-change token cannot update the account', function () {
    $user = User::factory()->create(['email' => 'current@example.com']);

    Notification::fake([ApproveEmailChange::class, VerifyPendingEmail::class]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'replacement@example.com')
        ->set('emailConfirmation', 'replacement@example.com')
        ->set('phone', $user->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $verificationUrl = null;
    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        function (VerifyPendingEmail $notification) use (&$verificationUrl): bool {
            $verificationUrl = $notification->url;

            return true;
        },
    );

    $path = (string) parse_url($verificationUrl, PHP_URL_PATH);
    $invalidPath = preg_replace('~/[A-Za-z0-9]{64}$~', '/'.str_repeat('x', 64), $path);

    $this->get((string) $invalidPath)->assertForbidden();

    expect($user->fresh()->email)->toBe('current@example.com');
    $this->assertDatabaseHas('email_change_requests', [
        'user_id' => $user->id,
        'new_email' => 'replacement@example.com',
        'new_verified_at' => null,
    ]);
});

test('expired email-change links cannot update the account', function () {
    $user = User::factory()->create(['email' => 'current@example.com']);

    Notification::fake([ApproveEmailChange::class, VerifyPendingEmail::class]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'replacement@example.com')
        ->set('emailConfirmation', 'replacement@example.com')
        ->set('phone', $user->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $verificationUrl = null;
    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        function (VerifyPendingEmail $notification) use (&$verificationUrl): bool {
            $verificationUrl = $notification->url;

            return true;
        },
    );

    $verificationPath = (string) parse_url($verificationUrl, PHP_URL_PATH);
    $this->travel(61)->minutes();

    $this->get($verificationPath)->assertForbidden();

    expect($user->fresh()->email)->toBe('current@example.com');
    $this->assertDatabaseHas('email_change_requests', [
        'user_id' => $user->id,
        'new_email' => 'replacement@example.com',
    ]);
});

test('a pending new address cannot be claimed by a different account', function () {
    $currentUser = User::factory()->create(['email' => 'current@example.com']);
    $otherUser = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'other@example.com',
    ]);

    Notification::fake([ApproveEmailChange::class, VerifyPendingEmail::class]);

    $this->actingAs($currentUser);

    Livewire::test('pages::settings.profile')
        ->set('name', $currentUser->name)
        ->set('email', 'claimed@example.com')
        ->set('emailConfirmation', 'claimed@example.com')
        ->set('phone', $currentUser->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $this->actingAs($otherUser);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Changed Name')
        ->set('email', 'claimed@example.com')
        ->set('emailConfirmation', 'claimed@example.com')
        ->set('phone', $otherUser->phone ?? '')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);

    expect($otherUser->fresh()->name)->toBe('Original Name');
    expect($otherUser->fresh()->email)->toBe('other@example.com');
    Notification::assertSentOnDemandTimes(ApproveEmailChange::class, 1);
    Notification::assertSentOnDemandTimes(VerifyPendingEmail::class, 1);
});
