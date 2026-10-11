<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Place a print order, share your design, and follow its current status with PrintSync.">

        <title>PrintSync - Print projects, in sync</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance

        <script>
            if (!window.localStorage.getItem('flux.appearance')) {
                window.Flux.applyAppearance('dark');
            }
        </script>
    </head>
    <body class="min-h-screen bg-[var(--ps-background)] text-[var(--ps-text)] antialiased">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-[var(--ps-surface)] focus:px-4 focus:py-3">
            Skip to content
        </a>

        <div class="mx-auto flex min-h-screen max-w-7xl flex-col gap-12 px-5 py-5 sm:px-8 lg:gap-20 lg:px-10">
            <header class="ps-glass flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold tracking-tight" aria-label="PrintSync home">
                    <span class="ps-brand-mark" aria-hidden="true">PS</span>
                    <span class="text-lg">PrintSync</span>
                </a>

                <nav class="hidden items-center gap-7 text-sm text-[var(--ps-muted)] md:flex" aria-label="Main navigation">
                    <a class="transition hover:text-[var(--ps-text)]" href="#features">What you can do</a>
                    <a class="transition hover:text-[var(--ps-text)]" href="#how-it-works">How it works</a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    <x-appearance-toggle />
                    @auth
                        <a class="ps-button-primary hidden sm:inline-flex" href="{{ route('dashboard') }}">My workspace</a>
                    @else
                        @if (Route::has('login'))
                            <a class="ps-button-secondary hidden sm:inline-flex" href="{{ route('login') }}">Sign in</a>
                        @endif
                        @if (Route::has('register'))
                            <a class="ps-button-primary" href="{{ route('register') }}">Get started</a>
                        @endif
                    @endauth
                </div>
            </header>

            <main id="main-content" class="flex flex-1 flex-col gap-20 lg:gap-28">
                <section class="grid items-center gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16">
                    <div class="flex flex-col items-start gap-7">
                        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.22em] text-[var(--ps-accent)]">
                            <span class="h-2 w-2 rounded-full bg-[var(--ps-accent)]" aria-hidden="true"></span>
                            Your print project, in sync
                        </p>
                        <div class="flex flex-col gap-5">
                            <h1 class="max-w-2xl text-5xl font-semibold leading-[1.04] tracking-[-0.06em] sm:text-6xl lg:text-7xl">
                                Print projects,
                                <span class="text-[var(--ps-accent)]">without the guesswork.</span>
                            </h1>
                            <p class="max-w-xl text-base leading-7 text-[var(--ps-muted)] sm:text-lg">
                                Send your print request, include the details and design file, then check the current status from your customer account.
                            </p>
                        </div>
                        <div class="flex w-full flex-wrap items-center gap-3">
                            @auth
                                @if (auth()->user()->role === 'customer')
                                    <a class="ps-button-primary" href="{{ route('customer.orders.create') }}">
                                        Start an order
                                        <span aria-hidden="true">→</span>
                                    </a>
                                    <a class="ps-button-secondary" href="{{ route('customer.orders') }}">View my orders</a>
                                @else
                                    <a class="ps-button-primary" href="{{ route('dashboard') }}">Open your workspace</a>
                                @endif
                            @else
                                @if (Route::has('register'))
                                    <a class="ps-button-primary" href="{{ route('register') }}">
                                        Create your account
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @endif
                                @if (Route::has('login'))
                                    <a class="ps-button-secondary sm:hidden" href="{{ route('login') }}">Sign in</a>
                                @endif
                            @endauth
                        </div>
                        <p class="text-sm text-[var(--ps-muted)]">A clearer way to submit and follow your print order.</p>
                    </div>

                    <div class="ps-glass-raised relative overflow-hidden p-5 sm:p-8" aria-label="PrintSync order workflow illustration">
                        <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full bg-[var(--ps-accent-soft)] blur-3xl" aria-hidden="true"></div>
                        <div class="relative flex flex-col gap-7">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex flex-col gap-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--ps-muted)]">A simple workflow</p>
                                    <h2 class="text-2xl font-semibold tracking-tight">From brief to pickup</h2>
                                </div>
                                <span class="ps-brand-mark shrink-0" aria-hidden="true">PS</span>
                            </div>

                            <div class="ps-surface relative flex flex-col gap-5 overflow-hidden p-5 sm:p-7">
                                <div class="absolute right-0 top-0 h-2 w-24 rounded-bl-xl bg-[var(--ps-accent)]" aria-hidden="true"></div>
                                <div class="flex items-center gap-4">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">01</div>
                                    <div class="flex flex-col gap-1">
                                        <h3 class="font-semibold">Share your order details</h3>
                                        <p class="text-sm text-[var(--ps-muted)]">Choose a service and tell us what you need.</p>
                                    </div>
                                </div>
                                <div class="h-px bg-[var(--ps-border)]" aria-hidden="true"></div>
                                <div class="flex items-center gap-4">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">02</div>
                                    <div class="flex flex-col gap-1">
                                        <h3 class="font-semibold">Attach your design</h3>
                                        <p class="text-sm text-[var(--ps-muted)]">Keep your project file with its request.</p>
                                    </div>
                                </div>
                                <div class="h-px bg-[var(--ps-border)]" aria-hidden="true"></div>
                                <div class="flex items-center gap-4">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">03</div>
                                    <div class="flex flex-col gap-1">
                                        <h3 class="font-semibold">Follow its current status</h3>
                                        <p class="text-sm text-[var(--ps-muted)]">See the latest saved update in your orders.</p>
                                    </div>
                                </div>
                            </div>

                            <p class="text-sm leading-6 text-[var(--ps-muted)]">
                                Your order details stay together, from the first request through production updates.
                            </p>
                        </div>
                    </div>
                </section>

                <section id="features" class="flex scroll-mt-8 flex-col gap-8">
                    <div class="flex max-w-2xl flex-col gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--ps-accent)]">Designed for clarity</p>
                        <h2 class="ps-page-title">Everything your order needs, in one place.</h2>
                        <p class="leading-7 text-[var(--ps-muted)]">
                            Keep the important parts of a print request connected, without losing sight of what happens next.
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <article class="ps-surface flex flex-col gap-4 p-6">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">01</span>
                            <h3 class="text-lg font-semibold">A clear request</h3>
                            <p class="leading-6 text-[var(--ps-muted)]">Choose an order type, add a description and quantity, and include your design file.</p>
                        </article>
                        <article class="ps-surface flex flex-col gap-4 p-6">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">02</span>
                            <h3 class="text-lg font-semibold">Updates you can check</h3>
                            <p class="leading-6 text-[var(--ps-muted)]">Return to your order list or details page to see its current status.</p>
                        </article>
                        <article class="ps-surface flex flex-col gap-4 p-6">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-lg font-semibold text-[var(--ps-accent)]" aria-hidden="true">03</span>
                            <h3 class="text-lg font-semibold">The right view for your role</h3>
                            <p class="leading-6 text-[var(--ps-muted)]">Customer, production, cashier, and owner workspaces keep their tasks organized.</p>
                        </article>
                    </div>
                </section>

                <section id="how-it-works" class="ps-glass flex scroll-mt-8 flex-col gap-8 p-6 sm:p-9 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex max-w-2xl flex-col gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--ps-accent)]">Get started</p>
                        <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">Ready to get your project moving?</h2>
                        <p class="leading-7 text-[var(--ps-muted)]">Create an account to submit an order and keep track of its latest status.</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        @auth
                            @if (auth()->user()->role === 'customer')
                                <a class="ps-button-primary" href="{{ route('customer.orders.create') }}">Create an order</a>
                            @else
                                <a class="ps-button-primary" href="{{ route('dashboard') }}">Open your workspace</a>
                            @endif
                        @else
                            @if (Route::has('register'))
                                <a class="ps-button-primary" href="{{ route('register') }}">Create your account</a>
                            @endif
                            @if (Route::has('login'))
                                <a class="ps-button-secondary" href="{{ route('login') }}">Sign in</a>
                            @endif
                        @endauth
                    </div>
                </section>
            </main>

            <footer class="flex flex-col gap-3 border-t border-[var(--ps-border)] py-6 text-sm text-[var(--ps-muted)] sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-medium text-[var(--ps-text)]">
                    <span class="ps-brand-mark" aria-hidden="true">PS</span>
                    PrintSync
                </a>
                <p>Print projects, in sync.</p>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
