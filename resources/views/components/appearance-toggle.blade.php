<button
    type="button"
    x-data
    x-on:click="$flux.appearance = $flux.dark ? 'light' : 'dark'"
    x-bind:aria-label="$flux.dark ? 'Switch to light mode' : 'Switch to dark mode'"
    x-bind:aria-pressed="$flux.dark"
    class="ps-theme-toggle"
>
    <flux:icon.sun x-cloak x-show="$flux.dark" aria-hidden="true" />
    <flux:icon.moon x-cloak x-show="! $flux.dark" aria-hidden="true" />
    <span class="hidden sm:inline">Theme</span>
</button>
