<?php

use App\Http\Requests\StoreInventoryAlertRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreStockInRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * @param  class-string<FormRequest>  $requestClass
 */
function validateFormRequest(string $requestClass, array $input): ValidatorInstance
{
    return Validator::make($input, (new $requestClass)->rules());
}

function createOrderForRequestValidation(): Order
{
    $customer = User::factory()->create(['role' => 'customer']);

    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'service_line' => 'book_binding',
        'quantity' => 1,
    ]);
}

function createInventoryForRequestValidation(): stdClass
{
    return (object) [
        'inventory_id' => DB::table('inventory')->insertGetId([
            'material_name' => 'Paper',
            'quantity' => '10.000',
            'unit' => 'ream',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'inventory_id'),
    ];
}

test('order validation prohibits client-managed attributes', function (string $attribute, mixed $value) {
    $validator = validateFormRequest(StoreOrderRequest::class, [
        $attribute => $value,
        'order_type' => 'poster',
        'quantity' => 1,
    ]);

    expect($validator->errors()->has($attribute))->toBeTrue();
})->with([
    'customer_id' => ['customer_id', 10],
    'order date' => ['order_date', '2026-10-08 12:00:00'],
    'status' => ['status', 'completed'],
]);

test('order validation requires order_type', function () {
    $validator = validateFormRequest(StoreOrderRequest::class, [
        'quantity' => 1,
    ]);

    expect($validator->errors()->has('order_type'))->toBeTrue();
});

test('order validation requires quantity', function () {
    $validator = validateFormRequest(StoreOrderRequest::class, [
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
    ]);

    expect($validator->errors()->has('quantity'))->toBeTrue();
});

test('order validation requires quantity of at least one', function () {
    $validator = validateFormRequest(StoreOrderRequest::class, [
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'quantity' => 0,
    ]);

    expect($validator->errors()->has('quantity'))->toBeTrue();
});

test('a valid order payload passes validation', function () {
    $validator = validateFormRequest(StoreOrderRequest::class, [
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'description' => 'Event poster',
        'design_file' => UploadedFile::fake()->create('design.pdf', 1000, 'application/pdf'),
        'quantity' => 2,
    ]);

    expect($validator->passes())->toBeTrue();
});

test('payment validation requires order_id', function () {
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('order_id'))->toBeTrue();
});

test('payment validation accepts an existing orders.order_id', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('order_id'))->toBeFalse();
});

test('payment validation rejects a nonexistent orders.order_id', function () {
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => 999999,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('order_id'))->toBeTrue();
});

test('payment validation prohibits cashier_id', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'cashier_id' => 10,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('cashier_id'))->toBeTrue();
});

test('payment validation accepts each supported payment method', function (string $paymentMethod) {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => $paymentMethod,
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('payment_method'))->toBeFalse();
})->with([
    'cash' => ['cash'],
    'gcash' => ['gcash'],
    'bank transfer' => ['bank_transfer'],
]);

test('payment validation rejects unsupported payment methods', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'online_gateway',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('payment_method'))->toBeTrue();
});

test('payment validation requires received', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('received'))->toBeTrue();
});

test('payment validation requires a numeric received amount', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'received' => 'not-a-number',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('received'))->toBeTrue();
});

test('payment validation rejects a negative received amount', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'received' => '-0.01',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->errors()->has('received'))->toBeTrue();
});

test('payment validation requires payment_date', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'received' => '100.00',
    ]);

    expect($validator->errors()->has('payment_date'))->toBeTrue();
});

test('a valid payment payload passes validation', function () {
    $order = createOrderForRequestValidation();
    $validator = validateFormRequest(StorePaymentRequest::class, [
        'order_id' => $order->order_id,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ]);

    expect($validator->passes())->toBeTrue();
});

test('stock-in validation requires inventory_id', function () {
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'quantity_added' => 1,
    ]);

    expect($validator->errors()->has('inventory_id'))->toBeTrue();
});

test('stock-in validation accepts an existing inventory.inventory_id', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => 1,
    ]);

    expect($validator->errors()->has('inventory_id'))->toBeFalse();
});

test('stock-in validation rejects a nonexistent inventory.inventory_id', function () {
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => 999999,
        'quantity_added' => 1,
    ]);

    expect($validator->errors()->has('inventory_id'))->toBeTrue();
});

test('stock-in validation prohibits staff_id', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => 10,
        'quantity_added' => 1,
    ]);

    expect($validator->errors()->has('staff_id'))->toBeTrue();
});

test('stock-in validation requires quantity_added', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
    ]);

    expect($validator->errors()->has('quantity_added'))->toBeTrue();
});

test('stock-in validation requires numeric quantity_added', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => 'not-a-number',
    ]);

    expect($validator->errors()->has('quantity_added'))->toBeTrue();
});

test('stock-in validation requires quantity_added greater than zero', function (int $quantity) {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => $quantity,
    ]);

    expect($validator->errors()->has('quantity_added'))->toBeTrue();
})->with([
    'zero' => [0],
    'negative quantity' => [-1],
]);

test('stock-in validation allows date_added to be omitted', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => 1,
    ]);

    expect($validator->errors()->has('date_added'))->toBeFalse();
});

test('stock-in validation rejects an invalid date_added', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => 1,
        'date_added' => 'not-a-date',
    ]);

    expect($validator->errors()->has('date_added'))->toBeTrue();
});

test('a valid stock-in payload passes validation', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreStockInRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => '1.250',
        'date_added' => '2026-10-08 12:00:00',
    ]);

    expect($validator->passes())->toBeTrue();
});

test('inventory-alert validation requires inventory_id', function () {
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, []);

    expect($validator->errors()->has('inventory_id'))->toBeTrue();
});

test('inventory-alert validation accepts an existing inventory.inventory_id', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
    ]);

    expect($validator->errors()->has('inventory_id'))->toBeFalse();
});

test('inventory-alert validation rejects a nonexistent inventory.inventory_id', function () {
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => 999999,
    ]);

    expect($validator->errors()->has('inventory_id'))->toBeTrue();
});

test('inventory-alert validation prohibits staff_id', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => 10,
    ]);

    expect($validator->errors()->has('staff_id'))->toBeTrue();
});

test('inventory-alert validation prohibits client-selected alert types', function (string $alertType) {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'alert_type' => $alertType,
    ]);

    expect($validator->errors()->has('alert_type'))->toBeTrue();
})->with([
    'low_stock' => ['low_stock'],
    'out_of_stock' => ['out_of_stock'],
]);

test('inventory-alert validation allows alert_date to be omitted', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
    ]);

    expect($validator->errors()->has('alert_date'))->toBeFalse();
});

test('inventory-alert validation rejects an invalid alert_date', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'alert_date' => 'not-a-date',
    ]);

    expect($validator->errors()->has('alert_date'))->toBeTrue();
});

test('inventory-alert validation allows remarks to be omitted', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
    ]);

    expect($validator->errors()->has('remarks'))->toBeFalse();
});

test('inventory-alert validation requires remarks to be a string when provided', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'remarks' => ['not', 'a', 'string'],
    ]);

    expect($validator->errors()->has('remarks'))->toBeTrue();
});

test('a valid low-stock alert payload passes validation', function () {
    $inventory = createInventoryForRequestValidation();
    $validator = validateFormRequest(StoreInventoryAlertRequest::class, [
        'inventory_id' => $inventory->inventory_id,
        'alert_date' => '2026-10-08 12:00:00',
        'remarks' => 'Supplies are running low.',
    ]);

    expect($validator->passes())->toBeTrue();
});
