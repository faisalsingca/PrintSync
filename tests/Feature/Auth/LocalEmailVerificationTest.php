<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Notification;

test('local registrations require email verification and send a verification notification', function () {
    if (! app()->environment('local')) {
        $this->markTestSkipped('This behavior is enabled only in the local environment.');
    }

    $this->withoutMiddleware(PreventRequestForgery::class);

    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Local Customer',
        'email' => 'local-customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-sent');

    $this->assertAuthenticated();

    $user = User::where('email', 'local-customer@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->get(route('customer.dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('appearance.edit'))->assertOk();

    Notification::assertSentTo($user, VerifyEmail::class);
});
