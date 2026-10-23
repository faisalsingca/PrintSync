@props(['tone' => 'neutral', 'label' => null])

{{--
    One look for every status in the system: a colored pill with an icon AND text,
    so it still reads clearly without relying on color alone.
    Tones: success (green), info (blue), progress (purple), warning (amber), danger (red), neutral (gray).
--}}
@php
    $tones = [
        'success' => ['border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', '✓'],
        'info' => ['border-sky-500/40 bg-sky-500/15 text-sky-700 dark:text-sky-300', 'i'],
        'progress' => ['border-violet-500/40 bg-violet-500/15 text-violet-700 dark:text-violet-300', '↻'],
        'warning' => ['border-amber-500/50 bg-amber-500/15 text-amber-700 dark:text-amber-300', '!'],
        'danger' => ['border-red-500/40 bg-red-500/15 text-red-700 dark:text-red-300', '✕'],
        'neutral' => ['border-zinc-500/30 bg-zinc-500/10 text-zinc-600 dark:text-zinc-300', '•'],
    ];
    [$toneClasses, $icon] = $tones[$tone] ?? $tones['neutral'];
@endphp

<span {{ $attributes->class(['inline-flex w-fit items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-none', $toneClasses]) }}>
    <span aria-hidden="true" class="inline-flex size-4 items-center justify-center rounded-full bg-current/15 text-[10px] font-bold">{{ $icon }}</span>
    <span>{{ $slot->isEmpty() ? $label : $slot }}</span>
</span>