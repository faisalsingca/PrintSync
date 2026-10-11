<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

class ReceiptService
{
    /**
     * Retrieve the order and customer information needed for a receipt.
     */
    public function getOrderInformationForReceipt(Order $order): Order
    {
        return $order->load('customer');
    }

    /**
     * Retrieve the payment information needed for an order receipt.
     *
     * @return Collection<int, Payment>
     */
    public function getPaymentInformationForReceipt(Order $order): Collection
    {
        return $order->payments()->get();
    }
}
