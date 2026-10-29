<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Livewire;

test('super admin can see registered customer accounts but not employee accounts', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $customer = User::factory()->create([
        'name' => 'Maria Customer',
        'email' => 'maria@example.com',
        'phone' => '09171234567',
        'role' => 'customer',
    ]);
    User::factory()->create([
        'name' => 'Jamie Employee',
        'email' => 'jamie@example.com',
        'role' => 'staff',
    ]);

    $this->actingAs($superAdmin)
        ->get(route('super-admin.customers'))
        ->assertOk()
        ->assertSeeText($customer->name)
        ->assertSeeText($customer->email)
        ->assertSeeText($customer->phone)
        ->assertDontSeeText('Jamie Employee')
        ->assertDontSeeText('jamie@example.com');
});

test('super admin can search customers by name email or phone', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $customer = User::factory()->create([
        'name' => 'Maria Customer',
        'email' => 'maria@example.com',
        'phone' => '09171234567',
        'role' => 'customer',
    ]);
    User::factory()->create([
        'name' => 'Other Customer',
        'email' => 'other@example.com',
        'role' => 'customer',
    ]);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.customer-accounts')
        ->set('search', '09171234567')
        ->assertSee($customer->name)
        ->assertDontSee('Other Customer');
});

test('super admin can deactivate and reactivate customer accounts without removing history', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $customer = User::factory()->create([
        'name' => 'Maria Customer',
        'role' => 'customer',
    ]);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.customer-accounts')
        ->call('toggleCustomerAccount', $customer->id)
        ->assertSet('statusMessage', "{$customer->name}'s account was deactivated.")
        ->assertSeeText('Deactivated')
        ->call('toggleCustomerAccount', $customer->id)
        ->assertSet('statusMessage', "{$customer->name}'s account was activated.")
        ->assertSeeText('Active');

    expect($customer->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('subject_id', $customer->id)->pluck('action')->all())
        ->toBe(['admin.customer_deactivated', 'admin.customer_activated']);
});

test('super admin cannot change the status of a non-customer account from customer monitoring', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $staff = User::factory()->create(['role' => 'staff']);

    $this->actingAs($superAdmin);

    Livewire::test('pages::super-admin.customer-accounts')
        ->call('toggleCustomerAccount', $staff->id)
        ->assertNotFound();

    expect($staff->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('subject_id', $staff->id)->exists())->toBeFalse();
});

test('only super admin can view customer accounts', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('super-admin.customers'))
        ->assertForbidden();
})->with(['customer', 'staff', 'cashier', 'owner']);
