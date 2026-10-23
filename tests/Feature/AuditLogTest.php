<?php

use App\Models\Inventory;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use Livewire\Livewire;

function auditTestItem(string $name): Inventory
{
    return Inventory::query()->create([
        'material_name' => $name,
        'quantity' => '10.000',
        'unit' => 'roll',
        'low_stock_level' => '5',
        'status' => Inventory::STATUS_IN_STOCK,
    ]);
}

test('the owner can read the audit log', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $service = new InventoryService;
    $service->archiveMaterial(auditTestItem('Vinyl'), $cashier);
    $service->createMaterial(['material_name' => 'Laminate', 'quantity' => '3', 'unit' => 'roll'], $cashier);

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.audit-logs'))
        ->assertOk()
        ->assertSee($cashier->name)
        ->assertSee('Archived "Vinyl"')
        ->assertSee('Laminate');
});

test('the owner can filter the audit log by action and by search', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $service = new InventoryService;
    $service->archiveMaterial(auditTestItem('Vinyl'), $cashier);
    $service->createMaterial(['material_name' => 'Laminate', 'quantity' => '3', 'unit' => 'roll'], $cashier);

    $this->actingAs(User::factory()->create(['role' => 'owner']));

    Livewire::test('pages::owner.audit-logs')
        ->set('action', 'inventory.archived')
        ->assertSee('Vinyl')
        ->assertDontSee('Laminate')
        ->set('action', '')
        ->set('search', 'Laminate')
        ->assertSee('Laminate')
        ->assertDontSee('Vinyl');
});

test('nobody except the owner can open the audit log', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('owner.audit-logs'))
        ->assertForbidden();
})->with(['customer', 'staff', 'cashier']);

test('audit entries keep the actor name and role as written at the time', function () {
    $cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Casey Cashier']);

    (new InventoryService)->createMaterial(['material_name' => 'Tape', 'quantity' => '1', 'unit' => 'roll'], $cashier);

    $log = (new AuditLogService)->paginate()->first();

    expect($log->actor_name)->toBe('Casey Cashier')
        ->and($log->actor_role)->toBe('cashier')
        ->and($log->actionLabel())->toBe('Material added');
});

test('changes made without a signed-in person are recorded as the system', function () {
    (new InventoryService)->createMaterial(['material_name' => 'Seeded item', 'quantity' => '1', 'unit' => 'pc']);

    $this->assertDatabaseHas('audit_logs', ['actor_name' => 'System', 'user_id' => null, 'action' => 'inventory.created']);
});
