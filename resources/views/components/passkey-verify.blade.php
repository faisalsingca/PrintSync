@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with passkey'),
    'loadingLabel' => __('Waiting for passkey...'),
    'separator' => null,
])

<div data-passkey-verification>
    <flux:button
        type="button"
        variant="subtle"
        class="w-full"
        data-passkey-verify
        data-options-url="{{ route($optionsRoute) }}"
        data-submit-url="{{ route($submitRoute) }}"
        data-label="{{ $label }}"
        data-loading-label="{{ $loadingLabel }}"
    >
        {{ $label }}
    </flux:button>

    <p class="mt-2 text-sm text-red-500" aria-live="polite" data-passkey-error></p>

    @if ($separator)
        <div class="relative my-2 text-center text-sm text-zinc-500 dark:text-zinc-400">
            <span class="bg-white px-2 dark:bg-zinc-900">{{ $separator }}</span>
        </div>
    @endif
</div>
