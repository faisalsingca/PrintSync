<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class ReportService
{
    /**
     * @return array{order_count: int, confirmed_sales: string, payment_count: int, payment_total: string, payment_methods: Collection<int, \stdClass>, payments: Collection<int, Payment>}
     */
    public function salesAndPayments(Carbon $from, Carbon $to): array
    {
        $sales = Order::query()
            ->whereNotNull('confirmed_total')
            ->whereBetween('total_confirmed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(confirmed_total), 0) as confirmed_sales')
            ->first();

        $paymentSummary = Payment::query()
            ->whereBetween('payment_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as payment_count, COALESCE(SUM(received), 0) as payment_total')
            ->first();

        return [
            'order_count' => (int) $sales->order_count,
            'confirmed_sales' => number_format((float) $sales->confirmed_sales, 2, '.', ''),
            'payment_count' => (int) $paymentSummary->payment_count,
            'payment_total' => number_format((float) $paymentSummary->payment_total, 2, '.', ''),
            'payment_methods' => Payment::query()
                ->whereBetween('payment_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->selectRaw('payment_method, COUNT(*) as payment_count, COALESCE(SUM(received), 0) as payment_total')
                ->groupBy('payment_method')
                ->orderBy('payment_method')
                ->get(),
            'payments' => Payment::query()
                ->with(['order.customer', 'cashier'])
                ->whereBetween('payment_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->latest('payment_date')
                ->latest('payment_id')
                ->get(),
        ];
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function inventory(): Collection
    {
        return Inventory::query()
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('material_name')
            ->get();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function staffActivity(Carbon $from, Carbon $to): Collection
    {
        return AuditLog::query()
            ->whereIn('actor_role', ['staff', 'cashier'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->latest('created_at')
            ->latest('id')
            ->get();
    }
}
