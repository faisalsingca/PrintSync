<?php

namespace App\Services;

use App\Concerns\HandlesMoney;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    use HandlesMoney;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    /**
     * Record a payment received by the cashier outside PrintSync.
     *
     * The order must already have a confirmed total, and the payment may not push the
     * cumulative recorded payments above that total. The order row is locked so that
     * concurrent payments cannot exceed the confirmed total.
     */
    public function recordPayment(
        Order $order,
        User $cashier,
        string $paymentMethod,
        string $received,
        Carbon $paymentDate,
    ): Payment {
        Gate::forUser($cashier)->authorize('create', Payment::class);

        if ($cashier->role !== 'cashier') {
            throw new AuthorizationException;
        }

        if (! $this->isValidMoneyAmount($received)) {
            throw ValidationException::withMessages([
                'received' => 'Enter a valid payment amount greater than zero with at most two decimal places.',
            ]);
        }

        return DB::transaction(function () use ($order, $cashier, $paymentMethod, $received, $paymentDate): Payment {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->order_id);

            if ($lockedOrder->confirmed_total === null) {
                throw ValidationException::withMessages([
                    'order_id' => 'Confirm the order total before recording a payment.',
                ]);
            }

            $paidCents = $this->toCents((string) $lockedOrder->payments()->sum('received'));
            $confirmedCents = $this->toCents($lockedOrder->confirmed_total);
            $receivedCents = $this->toCents($received);

            if ($paidCents + $receivedCents > $confirmedCents) {
                throw ValidationException::withMessages([
                    'received' => 'The payment exceeds the remaining balance for this order.',
                ]);
            }

            return Payment::create([
                'order_id' => $lockedOrder->order_id,
                'cashier_id' => $cashier->id,
                'payment_method' => $paymentMethod,
                'received' => $this->normalizeMoneyAmount($received),
                'payment_date' => $paymentDate,
            ]);
        });
    }

    /**
     * Calculate the total amount paid for an order from its recorded payments.
     */
    public function getAmountPaid(Order $order): string
    {
        return $this->normalizeMoneyAmount((string) $order->payments()->sum('received'));
    }

    /**
     * Calculate the remaining balance for an order.
     */
    public function getRemainingBalance(Order $order): ?string
    {
        if ($order->confirmed_total === null) {
            return null;
        }

        $remainingCents = $this->toCents($order->confirmed_total) - $this->toCents($this->getAmountPaid($order));

        return $this->normalizeMoneyAmount((string) ($remainingCents / 100));
    }

    /**
     * Determine the financial payment status for an order.
     *
     * This is derived from the confirmed total and the recorded payments and is kept
     * separate from the order's production status.
     */
    public function getPaymentStatus(Order $order): ?string
    {
        if ($order->confirmed_total === null) {
            return null;
        }

        $paidCents = $this->toCents($this->getAmountPaid($order));
        $confirmedCents = $this->toCents($order->confirmed_total);

        if ($paidCents === 0) {
            return self::STATUS_UNPAID;
        }

        if ($paidCents >= $confirmedCents) {
            return self::STATUS_PAID;
        }

        return self::STATUS_PARTIAL;
    }

    /**
     * Retrieve payments recorded for an order.
     *
     * @return Collection<int, Payment>
     */
    public function getOrderPayments(Order $order): Collection
    {
        return $order->payments()->get();
    }

    /**
     * Retrieve payments recorded by a cashier.
     *
     * @return Collection<int, Payment>
     */
    public function getCashierPayments(User $cashier): Collection
    {
        return $cashier->payments()
            ->with('order.customer')
            ->latest('payment_date')
            ->get();
    }

    /**
     * Retrieve payment totals recorded by a cashier.
     *
     * @return array{count: int, received: string}
     */
    public function getCashierPaymentSummary(User $cashier): array
    {
        return [
            'count' => $cashier->payments()->count(),
            'received' => (string) $cashier->payments()->sum('received'),
        ];
    }

    /**
     * Retrieve totals across saved payment records.
     *
     * @return array{count: int, received: string}
     */
    public function getPaymentSummary(): array
    {
        $summary = Payment::query()
            ->selectRaw('COUNT(*) as payment_count, COALESCE(SUM(received), 0) as received_total')
            ->first();

        return [
            'count' => (int) $summary->payment_count,
            'received' => (string) $summary->received_total,
        ];
    }

    /**
     * Retrieve the most recent saved payments.
     *
     * @return Collection<int, Payment>
     */
    public function getRecentPayments(int $limit = 5): Collection
    {
        return Payment::query()
            ->with('order.customer')
            ->latest('payment_date')
            ->latest('payment_id')
            ->limit($limit)
            ->get();
    }
}
