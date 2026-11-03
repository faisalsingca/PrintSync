<x-layouts::auth :title="$title">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="$title" :description="$message" />

        <form method="POST" action="{{ $confirmUrl }}" class="flex flex-col gap-6">
            @csrf

            <flux:button variant="primary" type="submit" class="w-full">
                {{ $button }}
            </flux:button>
        </form>

        <flux:link :href="route('login')" wire:navigate>{{ __('Return to log in') }}</flux:link>
    </div>
</x-layouts::auth>
