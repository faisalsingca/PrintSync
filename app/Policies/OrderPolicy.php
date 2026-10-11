<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\OrderService;

class OrderPolicy
{
    /**
     * Determine whether the user may create an order.
     */
    public function create(User $user): bool
    {
        return $user->role === 'customer';
    }

    /**
     * Determine whether the user may view the order.
     */
    /**
     * Determine whether the user may view the order.
     */
    public function view(User $user, Order $order): bool
    {
        return match ($user->role) {
            'customer' => $order->customer_id === $user->id,
            'staff' => app(OrderService::class)->staffCanServe($user, $order)
                || $order->assignments()
                    ->where('staff_id', $user->id)
                    ->whereIn('assignment_status', [
                        OrderAssignment::STATUS_OFFERED,
                        OrderAssignment::STATUS_ACCEPTED,
                    ])
                    ->where('is_active', true)
                    ->exists(),
            'cashier', 'owner' => true,
            default => false,
        };
    }

    /**
     * Determine whether the user may access the order's private design file.
     */
    public function viewDesignFile(User $user, Order $order): bool
    {
        return match ($user->role) {
            'customer' => $order->customer_id === $user->id,
            'staff' => $order->assignments()
                ->where('staff_id', $user->id)
                ->whereIn('assignment_status', [
                    OrderAssignment::STATUS_OFFERED,
                    OrderAssignment::STATUS_ACCEPTED,
                ])
                ->where('is_active', true)
                ->exists(),
            'owner' => true,
            default => false,
        };
    }

    /**
     * Determine whether the user may update production status.
     */
    public function updateProductionStatus(User $user, Order $order): bool
    {
        return $user->role === 'staff'
            && (app(OrderService::class)->staffCanServe($user, $order)
                || $order->assignments()
                    ->where('staff_id', $user->id)
                    ->where('assignment_status', OrderAssignment::STATUS_ACCEPTED)
                    ->where('is_active', true)
                    ->exists());
    }

    /**
     * Determine whether the user may confirm the order's total price.
     */
    public function confirmTotal(User $user, Order $order): bool
    {
        return in_array($user->role, ['cashier', 'owner'], true);
    }

    /**
     * Determine whether the user may route orders to staff.
     */
    public function route(User $user, Order $order): bool
    {
        return $user->role === 'owner';
    }

    /**
     * Determine whether the user may manage staff specialty mappings.
     */
    public function manageStaffSpecialties(User $user): bool
    {
        return $user->role === 'owner';
    }

    /**
     * Determine whether the user may respond to this active assignment offer.
     */
    public function respondToAssignment(User $user, Order $order, OrderAssignment $assignment): bool
    {
        return $user->role === 'staff'
            && $assignment->order_id === $order->order_id
            && $assignment->staff_id === $user->id
            && $assignment->assignment_status === OrderAssignment::STATUS_OFFERED
            && $assignment->is_active;
    }
}
