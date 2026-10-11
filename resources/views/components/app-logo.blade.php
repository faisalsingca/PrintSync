@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="PrintSync" {{ $attributes }}>
        <x-slot name="logo">
            <span class="ps-brand-mark" aria-hidden="true">PS</span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="PrintSync" {{ $attributes }}>
        <x-slot name="logo">
            <span class="ps-brand-mark" aria-hidden="true">PS</span>
        </x-slot>
    </flux:brand>
@endif
