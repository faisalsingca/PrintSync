<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderAssignment;
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
        return Order::create($attributes);
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

            $hasAcceptedAssignment = OrderAssignment::query()
                ->where('order_id', $lockedOrder->order_id)
                ->where('staff_id', $staff->id)
                ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
                ->where('is_active', true)
                ->exists();

            if (! $hasAcceptedAssignment) {
                throw new AuthorizationException;
            }

            $currentStatus = OrderStatus::tryFrom($lockedOrder->status);

            if ($currentStatus?->next() !== $status) {
                throw ValidationException::withMessages([
                    'status' => 'Select the next valid production status for this order.',
                ]);
            }

            $lockedOrder->status = $status->value;
            $lockedOrder->save();

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
        return Order::query()
            ->with([
                'customer',
                'assignments' => fn ($query) => $query->where('is_active', true)->with('staff'),
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
     * Automatically assign an unassigned order to the eligible staff member with the lightest workload.
     *
     * Staff who share a specialty take turns: the member with the fewest unfinished
     * assigned orders is chosen first, then the one who was assigned an order least
     * recently. The assignment is accepted immediately (no offer step). Returns null
     * when the order has no valid service line, already has an active assignment, or
     * no eligible staff member exists yet.
     */
    public function autoAssignOrder(Order $order): ?OrderAssignment
    {
        $serviceLine = is_string($order->service_line)
            ? OrderServiceLine::tryFrom($order->service_line)
            : null;

        if ($serviceLine === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $serviceLine): ?OrderAssignment {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if ($lockedOrder->assignments()->where('is_active', true)->exists()) {
                return null;
            }

            $candidateIds = $this->getEligibleStaff($serviceLine, $lockedOrder)->pluck('id');

            if ($candidateIds->isEmpty()) {
                return null;
            }

            $openCounts = OrderAssignment::query()
                ->join('orders', 'orders.order_id', '=', 'order_assignments.order_id')
                ->where('order_assignments.is_active', true)
                ->where('orders.status', '!=', OrderStatus::Completed->value)
                ->whereIn('order_assignments.staff_id', $candidateIds)
                ->groupBy('order_assignments.staff_id')
                ->selectRaw('order_assignments.staff_id as staff_id, COUNT(*) as open_count')
                ->pluck('open_count', 'staff_id');

            $lastAssigned = OrderAssignment::query()
                ->whereIn('staff_id', $candidateIds)
                ->groupBy('staff_id')
                ->selectRaw('staff_id, MAX(assignment_id) as last_assignment_id')
                ->pluck('last_assignment_id', 'staff_id');

            $sortKey = fn (int $staffId): array => [
                (int) $openCounts->get($staffId, 0),
                (int) $lastAssigned->get($staffId, 0),
                $staffId,
            ];

            $staffId = $candidateIds
                ->sort(fn (int $a, int $b): int => $sortKey($a) <=> $sortKey($b))
                ->first();

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
        $assigned = 0;

        $orders = Order::query()
            ->whereNotNull('service_line')
            ->where('status', '!=', OrderStatus::Completed->value)
            ->whereDoesntHave('assignments', fn ($query) => $query->where('is_active', true))
            ->orderBy('order_id')
            ->get();

        foreach ($orders as $order) {
            if ($this->autoAssignOrder($order) !== null) {
                $assigned++;
            }
        }

        return $assigned;
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
        return Order::query()
            ->whereHas('assignments', fn ($query) => $query
                ->where('staff_id', $staff->id)
                ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
                ->where('is_active', true))
            ->with('customer')
            ->get();
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
