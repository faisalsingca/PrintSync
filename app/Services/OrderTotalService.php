<?php

namespace App\Services;

use App\Concerns\HandlesMoney;
use App\Models\Order;
use App\Models\OrderTotalHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderTotalService
{
    use HandlesMoney;

    /**
     * Confirm or increase an order's total price and record the change in its audit history.
     *
     * The order update and the history record are written inside a single transaction,
     * and the order row is locked so concurrent confirmations cannot produce a total
     * that disagrees with the recorded history.
     */
    public function confirmTotal(
        Order $order,
        User $user,
        string $proposedTotal,
        ?string $reason = null,
        ?Carbon $confirmedAt = null,
    ): OrderTotalHistory {
        Gate::forUser($user)->authorize('confirmTotal', $order);

        if (! $this->isValidMoneyAmount($proposedTotal)) {
            throw ValidationException::withMessages([
                'confirmed_total' => 'Enter a valid total greater than zero with at most two decimal places.',
            ]);
        }

        if ($user->role !== 'cashier' && $user->role !== 'owner') {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($order, $user, $proposedTotal, $reason, $confirmedAt): OrderTotalHistory {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            $previousTotal = $lockedOrder->confirmed_total;

            if ($previousTotal !== null && $this->toCents($proposedTotal) <= $this->toCents($previousTotal)) {
                throw ValidationException::withMessages([
                    'confirmed_total' => 'The new total must be greater than the already confirmed total.',
                ]);
            }

            $normalizedTotal = $this->normalizeMoneyAmount($proposedTotal);
            $timestamp = $confirmedAt ?? Carbon::now();

            $lockedOrder->confirmed_total = $normalizedTotal;
            $lockedOrder->total_confirmed_at = $timestamp;
            $lockedOrder->total_confirmed_by_id = $user->id;
            $lockedOrder->save();

            return $lockedOrder->totalHistories()->create([
                'previous_total' => $previousTotal,
                'new_total' => $normalizedTotal,
                'changed_by_id' => $user->id,
                'reason' => $reason,
            ]);
        });
    }
}
