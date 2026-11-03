<?php

use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\VerifyPendingEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

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
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

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
