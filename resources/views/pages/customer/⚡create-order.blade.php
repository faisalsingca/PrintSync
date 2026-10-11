<?php

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\OrderServiceLine;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Create New Order')] class extends Component
{
    use WithFileUploads;

    public mixed $order_type = '';

    public mixed $service_line = '';

    public mixed $description = null;

    public mixed $design_file = null;

    public mixed $quantity = 1;

    public function createOrder(OrderService $orderService): ?RedirectResponse
    {
        Gate::authorize('create', Order::class);

        // The order type is the service the customer picked from the dropdown.
        $this->order_type = is_string($this->service_line)
            ? (OrderServiceLine::tryFrom($this->service_line)?->label() ?? '')
            : '';

        $validated = $this->validate(Arr::except((new StoreOrderRequest)->rules(), [
            'customer_id',
            'order_date',
            'status',
        ]));
        $designFile = $validated['design_file'] ?? null;
        $designFilePath = $designFile instanceof UploadedFile
            ? $designFile->store('order-designs', 'local')
            : null;

        if ($designFile instanceof UploadedFile && ! is_string($designFilePath)) {
            $this->addError('design_file', 'The design file could not be stored. Please try again.');

            return null;
        }

        try {
            $order = $orderService->createOrder([
                'customer_id' => auth()->id(),
                'order_date' => now(),
                'order_type' => $validated['order_type'],
                'service_line' => $validated['service_line'],
                'description' => $validated['description'] ?? null,
                'design_file' => $designFilePath,
                'quantity' => (int) $validated['quantity'],
                'status' => 'pending',
            ]);
        } catch (\Throwable $exception) {
            if ($designFilePath !== null) {
                Storage::disk('local')->delete($designFilePath);
            }

            throw $exception;
        }

        // Hand the order to the staff member who handles this service (shared fairly between staff).
        try {
            $orderService->autoAssignOrder($order);
        } catch (\Throwable $exception) {
            report($exception);
        }

        session()->flash('status', 'Your order has been submitted successfully.');

        return redirect()->route('customer.orders');
    }
};
?>

<div class="customer-order-create-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div class="grid gap-3">
                <span class="inline-flex w-fit items-center gap-2 rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                    CUSTOMER WORKSPACE
                </span>
                <div class="grid gap-2">
                    <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">Create New Order</h1>
                    <p class="max-w-xl text-sm leading-6 text-[var(--ps-muted)] sm:text-base">
                        Tell us what you would like printed. Your order will start in pending status for our team to review.
                    </p>
                </div>
            </div>

            <a href="{{ route('customer.orders') }}" wire:navigate class="ps-button-ghost w-fit">
                Back to orders
            </a>
        </div>
    </section>

    <form wire:submit="createOrder" class="ps-glass grid gap-7 p-5 sm:p-8">
        <div class="grid gap-6 md:grid-cols-2">
            <flux:field>
                <flux:label for="service_line">Order type</flux:label>
                <select id="service_line" wire:model="service_line" class="ps-input" required>
                    <option value="">Select an order type</option>
                    @foreach (OrderServiceLine::cases() as $serviceLine)
                        <option value="{{ $serviceLine->value }}">{{ $serviceLine->label() }}</option>
                    @endforeach
                </select>
                <p class="text-xs leading-5 text-[var(--ps-muted)]">Your order goes straight to the team member who handles this service.</p>
                <flux:error name="service_line" />
                <flux:error name="order_type" />
            </flux:field>

            <flux:field>
                <flux:label for="quantity">Quantity</flux:label>
                <flux:input id="quantity" wire:model="quantity" type="number" min="1" step="1" required />
                <flux:error name="quantity" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label for="description">Description <span class="font-normal text-[var(--ps-muted)]">(optional)</span></flux:label>
                <flux:textarea
                    id="description"
                    wire:model="description"
                    rows="5"
                    placeholder="Add sizes, paper preferences, finishing details, or other instructions."
                />
                <flux:error name="description" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label for="design_file">Design file <span class="font-normal text-[var(--ps-muted)]">(optional)</span></flux:label>
                <input
                    id="design_file"
                    type="file"
                    wire:model="design_file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    class="ps-input file:me-3 file:rounded-md file:border-0 file:bg-[var(--ps-accent-soft)] file:px-3 file:py-2 file:text-sm file:font-semibold file:text-[var(--ps-text)]"
                >
                <p class="text-xs leading-5 text-[var(--ps-muted)]">PDF, JPG, or PNG up to 10 MB.</p>
                <div wire:loading wire:target="design_file" class="text-sm text-[var(--ps-muted)]">Uploading design file...</div>
                @if ($design_file instanceof \Illuminate\Http\UploadedFile)
                    <p class="text-sm text-[var(--ps-muted)]">{{ $design_file->getClientOriginalName() }}</p>
                @endif
                <flux:error name="design_file" />
            </flux:field>
        </div>

        <div class="flex flex-col-reverse gap-3 border-t border-[var(--ps-border)] pt-5 sm:flex-row sm:justify-end">
            <a href="{{ route('customer.orders') }}" wire:navigate class="ps-button-secondary">Cancel</a>
            <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="createOrder">
                <span wire:loading.remove wire:target="createOrder">Submit Order</span>
                <span wire:loading wire:target="createOrder">Submitting...</span>
            </button>
        </div>
    </form>
</div>