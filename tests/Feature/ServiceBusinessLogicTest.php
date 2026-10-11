<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\OrderServiceLine;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function createServiceTestOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'service_line' => OrderServiceLine::GraphicDesign->value,
        'quantity' => 1,
        'status' => 'pending',
    ], $attributes));
}

function exposeSingularInventoryTablesToModels(): void
{
    DB::statement(
        'CREATE VIEW inventories AS
        SELECT inventory_id, material_name, quantity, unit, status, created_at, updated_at
        FROM inventory'
    );

    DB::statement(
        'CREATE TRIGGER inventories_update INSTEAD OF UPDATE ON inventories
        BEGIN
            UPDATE inventory
            SET material_name = NEW.material_name,
                quantity = NEW.quantity,
                unit = NEW.unit,
                status = NEW.status,
                created_at = NEW.created_at,
                updated_at = NEW.updated_at
            WHERE inventory_id = OLD.inventory_id;
        END'
    );

    DB::statement(
        'CREATE VIEW stock_ins AS
        SELECT stock_in_id, inventory_id, staff_id, quantity_added, date_added, created_at, updated_at
        FROM stock_in'
    );

    DB::statement(
        'CREATE TRIGGER stock_ins_insert INSTEAD OF INSERT ON stock_ins
        BEGIN
            INSERT INTO stock_in (
                inventory_id, staff_id, quantity_added, date_added, created_at, updated_at
            ) VALUES (
                NEW.inventory_id, NEW.staff_id, NEW.quantity_added, NEW.date_added,
                NEW.created_at, NEW.updated_at
            );
        END'
    );
}

function createServiceTestInventory(string $materialName, string $quantity = '10.000'): Inventory
{
    $inventoryId = DB::table('inventory')->insertGetId([
        'material_name' => $materialName,
        'quantity' => $quantity,
        'unit' => 'ream',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
    ], 'inventory_id');

    return Inventory::query()->findOrFail($inventoryId);
}

function createServiceTestPayment(Order $order, User $cashier, string $paymentMethod = 'cash'): Payment
{
    return Payment::create([
        'order_id' => $order->order_id,
        'cashier_id' => $cashier->id,
        'payment_method' => $paymentMethod,
        'received' => '25.50',
        'payment_date' => Carbon::parse('2026-10-08 14:30:00'),
    ]);
}

function confirmServiceTestOrderTotal(Order $order, string $total): Order
{
    $order->confirmed_total = $total;
    $order->total_confirmed_at = now();
    $order->save();

    return $order;
}

test('the order service creates an order from the supplied attributes', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $attributes = [
        'customer_id' => $customer->id,
        'order_date' => Carbon::parse('2026-10-08 10:00:00'),
        'order_type' => 'business cards',
        'description' => 'Matte finish',
        'quantity' => 250,
    ];

    $order = (new OrderService)->createOrder($attributes);

    $this->assertModelExists($order);
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->order_type)->toBe('business cards')
        ->and($order->quantity)->toBe(250);
});

test('the order service updates order status', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createServiceTestOrder($customer);

    $updatedOrder = (new OrderService)->updateOrderStatus($order, 'processing');

    expect($updatedOrder->fresh()->status)->toBe('processing');
});

test('the order service assigns an order to the supplied staff user', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $order = createServiceTestOrder($customer);
    StaffSpecialty::create([
        'staff_id' => $staff->id,
        'specialty' => 'graphic_designer',
    ]);

    $assignment = (new OrderService)->assignOrderToStaff($order, $staff);

    $this->assertModelExists($assignment);
    expect($assignment->order_id)->toBe($order->order_id)
        ->and($assignment->staff_id)->toBe($staff->id);
});

test('the order service returns only the supplied customer orders', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);
    $customerOrder = createServiceTestOrder($customer);
    createServiceTestOrder($otherCustomer);

    $orders = (new OrderService)->getCustomerOrders($customer);

    expect($orders->modelKeys())->toBe([$customerOrder->order_id]);
});

test('the inventory service increases quantity and records stock-in details', function () {
    exposeSingularInventoryTablesToModels();
    $inventory = createServiceTestInventory('Paper');
    $staff = User::factory()->create(['role' => 'staff']);
    $dateAdded = Carbon::parse('2026-10-08 15:45:00');

    $stockIn = (new InventoryService)->addStock($inventory, $staff, '2.500', $dateAdded);

    expect(Inventory::query()->findOrFail($inventory->inventory_id)->quantity)
        ->toBe('12.500');
    expect(DB::table('stock_in')->where('stock_in_id', $stockIn->stock_in_id)->exists())->toBeTrue();
    expect($stockIn->inventory_id)->toBe($inventory->inventory_id)
        ->and($stockIn->staff_id)->toBe($staff->id)
        ->and($stockIn->quantity_added)->toBe('2.500')
        ->and(DB::table('stock_in')->where('stock_in_id', $stockIn->stock_in_id)->value('date_added'))
        ->toBe('2026-10-08 15:45:00');
});

test('the inventory service rolls back quantity changes if stock-in creation fails', function () {
    exposeSingularInventoryTablesToModels();
    $inventory = createServiceTestInventory('Paper');
    $staff = User::factory()->create(['role' => 'staff']);

    DB::statement(
        "CREATE TRIGGER fail_stock_in_insert BEFORE INSERT ON stock_in
        BEGIN
            SELECT RAISE(ABORT, 'stock-in insert failed');
        END"
    );

    expect(fn () => (new InventoryService)->addStock($inventory, $staff, '2.500'))
        ->toThrow(QueryException::class);

    expect(Inventory::query()->findOrFail($inventory->inventory_id)->quantity)
        ->toBe('10.000')
        ->and(DB::table('stock_in')->count())->toBe(0);
});

test('the inventory service creates low-stock alerts with the supplied details', function () {
    exposeSingularInventoryTablesToModels();
    $inventory = createServiceTestInventory('Cardstock');
    $staff = User::factory()->create(['role' => 'staff']);
    $alertDate = Carbon::parse('2026-10-08 16:00:00');

    $alert = (new InventoryService)->createLowStockAlert(
        $inventory,
        $staff,
        'Only a small amount remains.',
        $alertDate,
    );

    $this->assertModelExists($alert);
    expect($alert->inventory_id)->toBe($inventory->inventory_id)
        ->and($alert->staff_id)->toBe($staff->id)
        ->and($alert->alert_type)->toBe('low_stock')
        ->and($alert->remarks)->toBe('Only a small amount remains.')
        ->and($alert->alert_date->toDateTimeString())->toBe('2026-10-08 16:00:00');
});

test('the inventory service returns inventory ordered by material name', function () {
    exposeSingularInventoryTablesToModels();
    createServiceTestInventory('Vinyl');
    createServiceTestInventory('Cardstock');
    createServiceTestInventory('Banner paper');

    $inventoryItems = (new InventoryService)->getInventoryList();

    expect($inventoryItems->pluck('material_name')->all())
        ->toBe(['Banner paper', 'Cardstock', 'Vinyl']);
});

test('the payment service records an externally received payment', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createServiceTestOrder($customer);
    $paymentDate = Carbon::parse('2026-10-08 17:15:00');
    confirmServiceTestOrderTotal($order, '1500.25');

    $payment = (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'bank_transfer',
        '1500.25',
        $paymentDate,
    );

    $this->assertModelExists($payment);
    expect($payment->order_id)->toBe($order->order_id)
        ->and($payment->cashier_id)->toBe($cashier->id)
        ->and($payment->payment_method)->toBe('bank_transfer')
        ->and($payment->received)->toBe('1500.25')
        ->and($payment->payment_date->toDateTimeString())->toBe('2026-10-08 17:15:00');
});

test('the payment service retrieves only payments for the supplied order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createServiceTestOrder($customer);
    $otherOrder = createServiceTestOrder($customer);
    $payment = createServiceTestPayment($order, $cashier);
    createServiceTestPayment($otherOrder, $cashier);

    $payments = (new PaymentService)->getOrderPayments($order);

    expect($payments->modelKeys())->toBe([$payment->payment_id]);
});

test('the payment service retrieves only payments recorded by the supplied cashier', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $otherCashier = User::factory()->create(['role' => 'cashier']);
    $order = createServiceTestOrder($customer);
    $cashierPayment = createServiceTestPayment($order, $cashier);
    createServiceTestPayment($order, $otherCashier);

    $payments = (new PaymentService)->getCashierPayments($cashier);

    expect($payments->modelKeys())->toBe([$cashierPayment->payment_id]);
});

test('the receipt service returns the order with its customer loaded', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createServiceTestOrder($customer);

    $receiptOrder = (new ReceiptService)->getOrderInformationForReceipt($order);

    expect($receiptOrder->relationLoaded('customer'))->toBeTrue()
        ->and($receiptOrder->customer->is($customer))->toBeTrue();
});

test('the receipt service returns payment records for the supplied order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createServiceTestOrder($customer);
    $otherOrder = createServiceTestOrder($customer);
    $payment = createServiceTestPayment($order, $cashier);
    createServiceTestPayment($otherOrder, $cashier);

    $payments = (new ReceiptService)->getPaymentInformationForReceipt($order);

    expect($payments->modelKeys())->toBe([$payment->payment_id]);
});
