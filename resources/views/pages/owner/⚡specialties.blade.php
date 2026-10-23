<?php

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\StaffSpecialtyType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff Specialties')] class extends Component
{
    public Collection $staffRoutingRoster;

    public array $staffSpecialtySelections = [];

    public string $successMessage = '';

    public function mount(OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $this->staffRoutingRoster = $orderService->getStaffRoutingRoster();

        foreach ($this->staffRoutingRoster as $staff) {
            $this->staffSpecialtySelections[$staff->id] = $staff->specialties
                ->pluck('specialty')
                ->all();
        }
    }

    public function updateStaffSpecialties(int $staffId, OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $specialtiesProperty = "staffSpecialtySelections.$staffId";
        $this->validate([
            $specialtiesProperty => ['array'],
            "$specialtiesProperty.*" => [Rule::enum(StaffSpecialtyType::class)],
        ]);

        $staff = User::query()
            ->where('role', 'staff')
            ->findOrFail($staffId);

        $orderService->updateStaffSpecialties(
            $staff,
            $this->staffSpecialtySelections[$staffId] ?? [],
        );

        $this->staffRoutingRoster = $orderService->getStaffRoutingRoster();
        $this->successMessage = "Specialties updated for {$staff->name}.";

        // Orders that were waiting for a matching staff member are assigned automatically.
        $assigned = $orderService->autoAssignUnassignedOrders();

        if ($assigned > 0) {
            $this->successMessage .= " {$assigned} waiting ".str('order')->plural($assigned).' assigned automatically.';
        }
    }
};
?>

<div class="owner-specialties-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Staff Specialties</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">Orders are assigned automatically to staff whose specialty matches the order type. Machine operators are limited to their documented support lines.</p>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="flex items-start gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            <span aria-hidden="true" class="font-bold text-emerald-500">✓</span> {{ $successMessage }}
        </div>
    @endif

    <ul class="grid gap-4 lg:grid-cols-2">
        @forelse ($staffRoutingRoster as $staff)
            <li wire:key="owner-staff-specialties-{{ $staff->id }}" class="ps-glass grid gap-4 p-4 sm:p-5">
                <div class="grid gap-1">
                    <h3 class="font-semibold text-[var(--ps-text)]">{{ $staff->name }}</h3>
                    <p class="text-sm text-[var(--ps-muted)]">{{ $staff->active_assignment_count }} active {{ str('task')->plural($staff->active_assignment_count) }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (StaffSpecialtyType::cases() as $specialty)
                        <label class="flex items-center gap-2 text-sm text-[var(--ps-text)]">
                            <flux:checkbox
                                wire:model="staffSpecialtySelections.{{ $staff->id }}"
                                value="{{ $specialty->value }}"
                            />
                            <span>{{ $specialty->label() }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="staffSpecialtySelections.{{ $staff->id }}" />
                <button type="button" wire:click="updateStaffSpecialties({{ $staff->id }})" wire:loading.attr="disabled" class="ps-button-primary w-fit">
                    Save specialties
                </button>
            </li>
        @empty
            <li class="ps-glass px-4 py-8 text-sm text-[var(--ps-muted)]">No staff accounts are available.</li>
        @endforelse
    </ul>
</div>