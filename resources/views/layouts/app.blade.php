<x-layouts::app.sidebar :title="$title ?? null">
    <main class="ps-main-content">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
