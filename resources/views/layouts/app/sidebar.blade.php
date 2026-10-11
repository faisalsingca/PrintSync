<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
       <body
        class="ps-app-shell font-sans antialiased"
        x-data="{ railOpen: false, collapsed: localStorage.getItem('ps-rail-collapsed') === '1' }"
        x-init="$watch('collapsed', value => localStorage.setItem('ps-rail-collapsed', value ? '1' : '0'))"
        x-bind:class="{ 'ps-rail-collapsed': collapsed }"
        x-on:keydown.escape.window="railOpen = false"
    >
        <div class="ps-atmosphere" aria-hidden="true"></div>

        {{-- Right-side vertical navigation (same position for every role) --}}
        <x-app-rail />
        <div class="ps-rail-backdrop lg:hidden" x-cloak x-show="railOpen" x-on:click="railOpen = false"></div>

        <div class="ps-main-shell">
            <header class="ps-topbar">
                <span class="ps-topbar-title truncate">{{ $title ?? __('Workspace') }}</span>

                <div class="flex shrink-0 items-center gap-3">
                    <x-appearance-toggle />
                    <x-user-menu />
                    <button
                        type="button"
                        class="ps-theme-toggle"
                        x-on:click="window.matchMedia('(min-width: 64rem)').matches ? (collapsed = ! collapsed) : (railOpen = true)"
                        aria-label="Toggle navigation menu"
                    >
                        <flux:icon.bars-3 class="size-5" />
                    </button>
                </div>
            </header>

            {{ $slot }}
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
