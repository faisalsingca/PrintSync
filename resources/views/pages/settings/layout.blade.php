<div class="grid gap-6">
    <nav class="ps-settings-tabs" aria-label="{{ __('Settings') }}">
        <a href="{{ route('profile.edit') }}" wire:navigate class="ps-settings-tab" @if (request()->routeIs('profile.edit')) data-current @endif>
            <flux:icon.user class="size-4" /> {{ __('Profile') }}
        </a>
        <a href="{{ route('security.edit') }}" wire:navigate class="ps-settings-tab" @if (request()->routeIs('security.edit')) data-current @endif>
            <flux:icon.shield-check class="size-4" /> {{ __('Security') }}
        </a>
        <a href="{{ route('appearance.edit') }}" wire:navigate class="ps-settings-tab" @if (request()->routeIs('appearance.edit')) data-current @endif>
            <flux:icon.paint-brush class="size-4" /> {{ __('Appearance') }}
        </a>
    </nav>

    <section class="ps-glass grid max-w-3xl gap-1 p-6 sm:p-8">
        <flux:heading size="lg">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </section>
</div>
