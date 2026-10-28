<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('public registration always assigns the customer role', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'phone' => '09171234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'owner',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'role' => 'customer',
        'phone' => '09171234567',
    ]);

    $user = User::where('email', 'test@example.com')->firstOrFail();

    // New accounts are treated as verified straight away, so no verification email is sent.
    expect($user->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});
