<x-layouts::auth :title="__('Verify your login')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Enter your login code')"
            :description="__('We sent a 6-digit code to :email. It expires in :minutes minutes.', ['email' => $maskedEmail, 'minutes' => config('otp.expires_minutes')])"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($showLogHint)
            <p class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Development mode: email is set to "log", so the code is written to
                <code>storage/logs/laravel.log</code> instead of being sent.
            </p>
        @endif

        <form method="POST" action="{{ route('otp.verify') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="code"
                :label="__('6-digit code')"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                autocomplete="one-time-code"
                required
                autofocus
                placeholder="123456"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="verify-otp-button">
                {{ __('Verify and log in') }}
            </flux:button>
        </form>

        <div class="flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('otp.resend') }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm">{{ __('Resend code') }}</flux:button>
            </form>
            <flux:link :href="route('login')">{{ __('Back to log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>