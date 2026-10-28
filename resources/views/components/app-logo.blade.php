@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="PrintSync" {{ $attributes }}>
        <x-slot name="logo">
            <img src="{{ asset('images/rpg-prints-logo.jpg') }}" alt="RPG Prints" class="ps-brand-logo">
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="PrintSync" {{ $attributes }}>
        <x-slot name="logo">
            <img src="{{ asset('images/rpg-prints-logo.jpg') }}" alt="RPG Prints" class="ps-brand-logo">
        </x-slot>
    </flux:brand>
@endif