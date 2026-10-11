<flux:dropdown position="bottom" align="end">
    <button type="button" class="ps-user-trigger" data-test="user-menu-button" aria-label="Open account menu">
        <flux:avatar
            size="sm"
            :name="auth()->user()->name"
            :initials="auth()->user()->initials()"
        />
        <span class="hidden text-start sm:block">
            <span class="ps-user-trigger-name">{{ auth()->user()->name }}</span>
            <span class="ps-user-trigger-role">{{ ucfirst(auth()->user()->role) }}</span>
        </span>
        <flux:icon.chevron-down class="size-4 text-[var(--ps-muted)]" />
    </button>

    <flux:menu>
        <div class="px-2 py-1.5 text-start text-sm leading-tight">
            <div class="truncate font-medium">{{ auth()->user()->name }}</div>
            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</div>
        </div>
        <flux:menu.separator />
        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
            {{ __('Settings') }}
        </flux:menu.item>
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
