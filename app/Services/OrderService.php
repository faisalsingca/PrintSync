<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderTakeover;
use App\Models\User;
use App\OrderServiceLine;
use App\OrderStatus;
use App\StaffSpecialtyType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Retrieve counts grouped by each order's current status.
     *
     * @return list<array{status: string, count: int}>
     */
    public function getOrderStatusCounts(): array
    {
        return Order::query()
            ->select('status')
            ->selectRaw('COUNT(*) as order_count')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (Order $order): array => [
                'status' => $order->status,
                'count' => (int) $order->order_count,
            ])
            ->all();
    }

    /**
     * Create an order from validated attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrder(array $attributes): Order
    {
        return DB::transaction(function () use ($attributes): Order {
            $order = Order::create($attributes);
            $this->autoAssignOrder($order);

            return $order;
        });
    }

    /**
     * Create an order for an in-person customer, linking an account when one was found.
     *
     * @param  array{order_type: string, service_line: string, description?: ?string, quantity: int}  $attributes
     */
    public function createWalkInOrder(string $customerName, array $attributes, ?User $customer = null): Order
    {
        return DB::transaction(function () use ($customerName, $attributes, $customer): Order {
            return $this->createOrder([
                ...$attributes,
                'customer_id' => $customer?->id,
                'customer_name' => $customer === null ? $customerName : null,
                'order_date' => now(),
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Update an order's status.
     */
    public function updateOrderStatus(Order $order, string $status): Order
    {
        $order->status = $status;
        $order->save();

        return $order;
    }

    /**
     * Advance an order through its production workflow for its assigned staff member.
     */
    public function updateAssignedOrderStatus(Order $order, User $staff, OrderStatus $status): Order
    {
        if ($staff->role !== 'staff') {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($order, $staff, $status): Order {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if (! $this->canUpdateOrder($staff, $lockedOrder)) {
                throw new AuthorizationException;
            }

            if (! app(PaymentService::class)->isReadyForProduction($lockedOrder)) {
                throw ValidationException::withMessages([
                    'status' => 'This order is awaiting payment. Production can start once the cashier records at least a partial payment.',
                ]);
            }

            $currentStatus = OrderStatus::tryFrom($lockedOrder->status);

            if ($currentStatus?->next() !== $status) {
                throw ValidationException::withMessages([
                    'status' => 'Select the next valid production status for this order.',
                ]);
            }

            $lockedOrder->status = $status->value;
            $lockedOrder->save();

            if ($status === OrderStatus::Completed) {
                OrderAssignment::query()
                    ->where('order_id', $lockedOrder->order_id)
                    ->where('is_active', true)
                    ->update(['is_active' => null]);
            }

            return $lockedOrder;
        });
    }

    /**
     * Retrieve orders that do not currently have an active staff assignment.
     *
     * @return Collection<int, Order>
     */
    public function getOrdersAwaitingRouting(): Collection
    {
        return Order::query()
            ->whereDoesntHave('assignments', fn ($query) => $query->where('is_active', true))
            ->where('status', '!=', OrderStatus::Completed->value)
            ->with('customer')
            ->latest('order_date')
            ->latest('order_id')
            ->get();
    }

    /**
     * Retrieve staff eligible for a service, excluding staff who declined that order.
     *
     * @return Collection<int, User>
     */
    public function getEligibleStaff(OrderServiceLine $serviceLine, Order $order): Collection
    {
        $eligibleSpecialties = $this->eligibleSpecialties($serviceLine);

        return User::query()
            ->where('role', 'staff')
            ->where('is_active', true)
            ->whereHas('specialties', fn ($query) => $query->whereIn('specialty', $eligibleSpecialties))
            ->whereDoesntHave('orderAssignments', fn ($query) => $query
                ->where('order_id', $order->order_id)
                ->where('assignment_status', OrderAssignment::STATUS_DECLINED))
            ->withCount(['orderAssignments as active_assignment_count' => fn ($query) => $query
                ->where('is_active', true)])
            ->orderBy('name')
            ->get();
    }

    /**
     * Retrieve staff and their specialty mappings for owner routing management.
     *
     * @return Collection<int, User>
     */
    public function getStaffRoutingRoster(): Collection
    {
        return User::query()
            ->where('role', 'staff')
            ->with('specialties')
            ->withCount(['orderAssignments as active_assignment_count' => fn ($query) => $query
                ->where('is_active', true)])
            ->orderBy('name')
            ->get();
    }

    /**
     * Replace a staff member's service specialty mapping.
     *
     * @param  list<string>  $specialties
     */
    public function updateStaffSpecialties(User $staff, array $specialties): void
    {
        if ($staff->role !== 'staff') {
            throw new AuthorizationException;
        }

        $specialties = array_values(array_unique($specialties));

        foreach ($specialties as $specialty) {
            if (! is_string($specialty) || StaffSpecialtyType::tryFrom($specialty) === null) {
                throw ValidationException::withMessages([
                    'staffSpecialtySelections' => 'Select only documented staff specialties.',
                ]);
            }
        }

        DB::transaction(function () use ($staff, $specialties): void {
            $lockedStaff = User::query()->lockForUpdate()->findOrFail($staff->id);
            $currentSpecialties = $lockedStaff->specialties()->pluck('specialty')->all();
            $specialtiesToRemove = array_values(array_diff($currentSpecialties, $specialties));
            $specialtiesToAdd = array_values(array_diff($specialties, $currentSpecialties));

            if ($specialtiesToRemove !== []) {
                $lockedStaff->specialties()->whereIn('specialty', $specialtiesToRemove)->delete();
            }

            if ($specialtiesToAdd !== []) {
                $lockedStaff->specialties()->createMany(array_map(
                    fn (string $specialty): array => ['specialty' => $specialty],
                    $specialtiesToAdd,
                ));
            }
        });
    }

    /**
     * Route an order to a specialty-eligible staff member as an offer awaiting response.
     */
    public function routeOrder(
        Order $order,
        User $staff,
        OrderServiceLine $serviceLine,
    ): OrderAssignment {
        return DB::transaction(function () use ($order, $staff, $serviceLine): OrderAssignment {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if ($lockedOrder->service_line !== null && $lockedOrder->service_line !== $serviceLine->value) {
                throw ValidationException::withMessages([
                    'routeServiceLine' => 'The service line for this order has already been set.',
                ]);
            }

            if (OrderAssignment::query()
                ->where('order_id', $lockedOrder->order_id)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'routeOrderId' => 'This order already has an active assignment.',
                ]);
            }

            $lockedStaff = User::query()->lockForUpdate()->findOrFail($staff->id);
            $isEligible = $lockedStaff->role === 'staff'
                && $lockedStaff->specialties()
                    ->whereIn('specialty', $this->eligibleSpecialties($serviceLine))
                    ->exists();

            if (! $isEligible) {
                throw ValidationException::withMessages([
                    'routeStaffId' => 'Select a staff member eligible for this service line.',
                ]);
            }

            $hasDeclined = OrderAssignment::query()
                ->where('order_id', $lockedOrder->order_id)
                ->where('staff_id', $lockedStaff->id)
                ->where('assignment_status', OrderAssignment::STATUS_DECLINED)
                ->exists();

            if ($hasDeclined) {
                throw ValidationException::withMessages([
                    'routeStaffId' => 'This staff member already declined this order.',
                ]);
            }

            $lockedOrder->service_line ??= $serviceLine->value;
            $lockedOrder->save();

            return OrderAssignment::create([
                'order_id' => $lockedOrder->order_id,
                'staff_id' => $lockedStaff->id,
                'assignment_status' => OrderAssignment::STATUS_OFFERED,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Retrieve task offers awaiting this staff member's response.
     *
     * @return Collection<int, OrderAssignment>
     */
    public function getStaffTaskOffers(User $staff): Collection
    {
        return $staff->orderAssignments()
            ->where('assignment_status', OrderAssignment::STATUS_OFFERED)
            ->where('is_active', true)
            ->with('order.customer')
            ->latest('assigned_date')
            ->latest('assignment_id')
            ->get();
    }

    /**
     * Record a staff member's decision for an offered assignment.
     */
    public function respondToAssignment(
        OrderAssignment $assignment,
        User $staff,
        bool $accept,
    ): OrderAssignment {
        if ($staff->role !== 'staff') {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($assignment, $staff, $accept): OrderAssignment {
            Order::query()
                ->lockForUpdate()
                ->findOrFail($assignment->order_id);

            $lockedAssignment = OrderAssignment::query()
                ->lockForUpdate()
                ->findOrFail($assignment->assignment_id);

            if (
                $lockedAssignment->staff_id !== $staff->id
                || $lockedAssignment->assignment_status !== OrderAssignment::STATUS_OFFERED
                || ! $lockedAssignment->is_active
            ) {
                throw ValidationException::withMessages([
                    'assignment' => 'This task is no longer awaiting your response.',
                ]);
            }

            $lockedAssignment->assignment_status = $accept
                ? OrderAssignment::STATUS_ACCEPTED
                : OrderAssignment::STATUS_DECLINED;
            $lockedAssignment->is_active = $accept ? true : null;
            $lockedAssignment->save();

            return $lockedAssignment;
        });
    }

    /**
     * Assign an order to a staff user.
     */
    public function assignOrderToStaff(Order $order, User $staff): OrderAssignment
    {
        if (! is_string($order->service_line)) {
            throw ValidationException::withMessages([
                'service_line' => 'Set the order service line before routing it.',
            ]);
        }

        $serviceLine = OrderServiceLine::tryFrom($order->service_line);

        if ($serviceLine === null) {
            throw ValidationException::withMessages([
                'service_line' => 'Select a supported service line before routing this order.',
            ]);
        }

        return $this->routeOrder($order, $staff, $serviceLine);
    }

    /**
     * Retrieve the latest orders with their active assignee for read-only owner monitoring.
     *
     * @return Collection<int, Order>
     */
    public function getRoutingOverview(int $limit = 50): Collection
    {
        $this->autoAssignUnassignedOrders();

        return Order::query()
            ->with([
                'customer',
                'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
                'takeovers' => fn ($query) => $query
                    ->with(['coveringStaff', 'assignment.staff'])
                    ->orderByDesc('takeover_id'),
            ])
            ->latest('order_date')
            ->latest('order_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Count unfinished orders that have a service line but no active assignment.
     */
    public function countUnassignedOrders(): int
    {
        return Order::query()
            ->whereNotNull('service_line')
            ->where('status', '!=', OrderStatus::Completed->value)
            ->whereDoesntHave('assignments', fn ($query) => $query->where('is_active', true))
            ->count();
    }

    /**
     * Automatically assign an unassigned order to the next eligible staff member in rotation.
     *
     * Staff are selected alphabetically and rotate by the most recent assignment for this service line.
     * The assignment is accepted immediately (no offer step). Returns null
     * when the order has no valid service line, already has an active assignment, or
     * no eligible staff member exists yet.
     */
    public function autoAssignOrder(Order $order): ?OrderAssignment
    {
        $serviceLine = is_string($order->service_line)
            ? OrderServiceLine::tryFrom($order->service_line)
            : null;

        if ($serviceLine === null || $order->status === OrderStatus::Completed->value) {
            return null;
        }

        return DB::transaction(function () use ($order, $serviceLine): ?OrderAssignment {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if ($lockedOrder->assignments()->where('is_active', true)->exists()) {
                return null;
            }

            $candidateIds = $this->getEligibleStaff($serviceLine, $lockedOrder)->modelKeys();

            if ($candidateIds === []) {
                return null;
            }

            $lockedCandidateIds = User::query()
                ->whereKey($candidateIds)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();
            $candidateIds = array_values(array_intersect($candidateIds, $lockedCandidateIds));

            if ($candidateIds === []) {
                return null;
            }

            $lastAssignedStaffId = (int) (OrderAssignment::query()
                ->whereIn('staff_id', $candidateIds)
                ->whereHas('order', fn ($query) => $query->where('service_line', $serviceLine->value))
                ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
                ->latest('assignment_id')
                ->lockForUpdate()
                ->value('staff_id') ?? 0);

            $lastIndex = array_search($lastAssignedStaffId, $candidateIds, true);
            $nextIndex = $lastIndex === false ? 0 : ($lastIndex + 1) % count($candidateIds);
            $staffId = $candidateIds[$nextIndex];

            return OrderAssignment::create([
                'order_id' => $lockedOrder->order_id,
                'staff_id' => $staffId,
                'assignment_status' => OrderAssignment::STATUS_ACCEPTED,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Try to auto-assign every unfinished order that has a service line but no active assignment.
     *
     * @return int Number of orders that received an assignment.
     */
    public function autoAssignUnassignedOrders(): int
    {
        $assignedCount = 0;

        foreach ($this->getOrdersAwaitingRouting() as $order) {
            if ($this->autoAssignOrder($order) !== null) {
                $assignedCount++;
            }
        }

        return $assignedCount;
    }

    /**
     * Retrieve all orders belonging to a customer.
     *
     * @return Collection<int, Order>
     */
    public function getCustomerOrders(User $customer): Collection
    {
        return $customer->orders()->get();
    }

    /**
     * Retrieve all orders assigned to a staff member.
     *
     * @return Collection<int, Order>
     */
    public function getStaffOrders(User $staff): Collection
    {
        $this->autoAssignUnassignedOrders();

        return Order::query()
            ->whereIn('service_line', $this->serviceLinesFor($staff))
            ->whereDoesntHave('assignments', fn ($query) => $query
                ->where('staff_id', $staff->id)
                ->where('assignment_status', OrderAssignment::STATUS_DECLINED))
            ->with([
                'customer',
                'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
                'takeovers' => fn ($query) => $query
                    ->with(['coveringStaff', 'assignment.staff'])
                    ->orderByDesc('takeover_id'),
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('order_id')
            ->get();
    }

    /**
     * The service lines a staff member can work on, based on their specialties.
     *
     * @return list<string>
     */
    public function serviceLinesFor(User $staff): array
    {
        $specialties = $staff->specialties()->pluck('specialty')->all();

        return collect(OrderServiceLine::cases())
            ->filter(fn (OrderServiceLine $line): bool => array_intersect($this->eligibleSpecialties($line), $specialties) !== [])
            ->map(fn (OrderServiceLine $line): string => $line->value)
            ->values()
            ->all();
    }

    /**
     * Every staff member with a matching specialty shares the orders of that service.
     */
    public function staffCanServe(User $staff, Order $order): bool
    {
        if ($staff->role !== 'staff' || ! is_string($order->service_line)) {
            return false;
        }

        return in_array($order->service_line, $this->serviceLinesFor($staff), true)
            && ! $order->assignments()
                ->where('staff_id', $staff->id)
                ->where('assignment_status', OrderAssignment::STATUS_DECLINED)
                ->exists();
    }

    public function canTakeOverOrder(User $staff, Order $order): bool
    {
        if (
            $staff->role !== 'staff'
            || $order->status === OrderStatus::Completed->value
            || ! $this->staffCanServe($staff, $order)
            || $order->takeovers()->exists()
        ) {
            return false;
        }

        return $order->assignments()
            ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
            ->where('is_active', true)
            ->where('staff_id', '!=', $staff->id)
            ->exists();
    }

    public function canUpdateOrder(User $staff, Order $order): bool
    {
        if (
            $staff->role !== 'staff'
            || $order->status === OrderStatus::Completed->value
            || ! $this->staffCanServe($staff, $order)
        ) {
            return false;
        }

        $assignment = $order->assignments()
            ->where('is_active', true)
            ->first();

        if ($assignment === null) {
            return false;
        }

        if ($assignment->assignment_status !== OrderAssignment::STATUS_ACCEPTED) {
            return false;
        }

        $takeover = $order->takeovers()->first();

        return $takeover === null
            ? $assignment->staff_id === $staff->id
            : $takeover->covering_staff_id === $staff->id;
    }

    public function takeOverOrder(Order $order, User $staff, string $reason): OrderTakeover
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'takeoverReason' => 'Explain why you are taking over this order.',
            ]);
        }

        return DB::transaction(function () use ($order, $staff, $reason): OrderTakeover {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if (! $this->canTakeOverOrder($staff, $lockedOrder)) {
                throw new AuthorizationException;
            }

            $assignment = OrderAssignment::query()
                ->where('order_id', $lockedOrder->order_id)
                ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            return OrderTakeover::create([
                'order_id' => $lockedOrder->order_id,
                'assignment_id' => $assignment->assignment_id,
                'covering_staff_id' => $staff->id,
                'reason' => trim($reason),
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function eligibleSpecialties(OrderServiceLine $serviceLine): array
    {
        return match ($serviceLine) {
            OrderServiceLine::GraphicDesign => [StaffSpecialtyType::GraphicDesigner->value],
            OrderServiceLine::BookBinding => [StaffSpecialtyType::BookBinder->value],
            OrderServiceLine::SewingSublimation => [
                StaffSpecialtyType::Sewer->value,
                StaffSpecialtyType::MachineOperator->value,
            ],
            OrderServiceLine::TarpaulinPrinting => [
                StaffSpecialtyType::TarpaulinEditor->value,
                StaffSpecialtyType::MachineOperator->value,
            ],
            OrderServiceLine::StickerProduction => [
                StaffSpecialtyType::StickerEditor->value,
                StaffSpecialtyType::MachineOperator->value,
            ],
            OrderServiceLine::SintraBoard,
            OrderServiceLine::Signage => [StaffSpecialtyType::MachineOperator->value],
        };
    }
}
