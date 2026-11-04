<x-layouts::auth :title="__('Accept staff invitation')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Complete your staff account')"
            :description="__('Choose a password to confirm your invitation and activate your account.')"
        />

        <div class="rounded-lg border border-[var(--ps-border)] p-4 text-sm">
            <p class="font-medium">{{ $invitation->name }}</p>
            <p class="text-[var(--ps-muted)]">{{ $invitation->email }}</p>
            <p class="mt-1 capitalize text-[var(--ps-muted)]">{{ $invitation->role }}</p>
        </div>

        <form method="POST" action="{{ route('staff-invitations.accept', ['token' => $token]) }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                id="password"
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <x-password-checklist for="password" confirm="password_confirmation" />

            <flux:input
                id="password_confirmation"
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:error name="password" />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Confirm invitation') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
