<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'PrintSync') : config('app.name', 'PrintSync') }}
</title>

<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

<script>
    if (!window.localStorage.getItem('flux.appearance')) {
        window.Flux.applyAppearance('dark');
    }
</script>
