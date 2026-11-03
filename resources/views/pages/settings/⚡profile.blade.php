<?php

use App\Concerns\ProfileValidationRules;
use App\Services\EmailChangeService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $emailConfirmation = '';
    public string $phone = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->emailChangeRequest?->new_email ?? Auth::user()->email;
        $this->phone = Auth::user()->phone ?? '';
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(EmailChangeService $emailChanges): void
    {
        $user = Auth::user();

        $pendingEmailChange = $user->emailChangeRequest;
        $requiresEmailConfirmation = $this->email !== $user->email
            && $this->email !== $pendingEmailChange?->new_email;

        $rules = $this->profileRules($user->id);
        $validationMessages = [];

        if ($requiresEmailConfirmation) {
            $rules['emailConfirmation'] = ['required', 'email', 'same:email'];
            $validationMessages = [
                'emailConfirmation.required' => __('Please confirm the new email address.'),
                'emailConfirmation.same' => __('The email addresses do not match.'),
            ];
        }

        $validated = $this->validate($rules, $validationMessages);
        $emailChanged = $validated['email'] !== $user->email;

        if ($emailChanged) {
            $emailChanges->assertEmailIsAvailable($user, $validated['email']);
        }

        $user->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
        ])->save();

        if ($emailChanged) {
            $requested = $emailChanges->requestChange($user, $validated['email']);
            $user->unsetRelation('emailChangeRequest');

            Session::flash('status', $requested
                ? __('Confirmation links were sent. Your current email stays in use until the change is confirmed.')
                : __('This email change is already pending. Check the confirmation emails we sent.'));
        } elseif ($user->emailChangeRequest()->exists()) {
            $emailChanges->cancelPending($user);
            $user->unsetRelation('emailChangeRequest');
            $this->email = $user->email;

            Session::flash('status', __('The pending email change was cancelled.'));
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Cancel the pending email change.
     */
    public function cancelEmailChange(EmailChangeService $emailChanges): void
    {
        $user = Auth::user();
        $emailChanges->cancelPending($user);
        $user->unsetRelation('emailChangeRequest');
        $this->email = $user->email;

        Session::flash('status', __('The pending email change was cancelled.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @php($pendingEmailChange = Auth::user()->emailChangeRequest()->first())

    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:text class="mb-2">
                    {{ __('Current email address: :email', ['email' => Auth::user()->email]) }}
                </flux:text>

                <flux:input wire:model.live="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($email !== Auth::user()->email && $email !== $pendingEmailChange?->new_email)
                    <flux:input
                        wire:model="emailConfirmation"
                        :label="__('Confirm new email')"
                        type="email"
                        required
                        autocomplete="off"
                    />
                    <flux:text class="mt-2">
                        {{ __('Enter the new address again to catch typing mistakes. We will send the verification email only if both addresses match.') }}
                    </flux:text>
                    @error('emailConfirmation') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                @endif

                @if ($pendingEmailChange)
                    <div class="mt-4 space-y-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p>
                            {{ __('A change to :email is pending. The current address will remain on your account until the required confirmations are complete.', ['email' => $pendingEmailChange->new_email]) }}
                        </p>

                        <p>{{ __('Confirmation links expire in one hour. If they expire, cancel this request and submit it again.') }}</p>

                        @if ($pendingEmailChange->old_token_hash)
                            <p>{{ __('Approve the change from your current verified email and verify the new address using the links we sent.') }}</p>
                        @else
                            <p>{{ __('Verify the new address using the link we sent. Your current address was not previously verified, so it does not need to approve the change.') }}</p>
                        @endif

                        <flux:button type="button" variant="ghost" size="sm" wire:click="cancelEmailChange">
                            {{ __('Cancel email change') }}
                        </flux:button>
                    </div>
                @endif

                @if ($this->hasUnverifiedEmail && ! $pendingEmailChange)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <flux:input wire:model="phone" :label="__('Phone number (optional)')" type="tel" autocomplete="tel" />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
