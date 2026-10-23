<?php

use App\Models\StaffSpecialty;
use App\Models\User;
use App\StaffSpecialtyType;
use Database\Seeders\StaffSeeder;

test('staff seeding creates two staff per specialty plus cashiers and an owner', function () {
    $this->seed(StaffSeeder::class);

    foreach (StaffSpecialtyType::cases() as $specialty) {
        $expected = $specialty === StaffSpecialtyType::InventoryClerk ? 0 : 2;

        expect(StaffSpecialty::query()->where('specialty', $specialty->value)->count())
            ->toBe($expected, "{$specialty->value} should have {$expected} staff");
    }

    expect(User::query()->where('role', 'staff')->count())->toBe(12)
        ->and(User::query()->where('role', 'cashier')->count())->toBe(2)
        ->and(User::query()->where('role', 'owner')->count())->toBe(1);
});

test('staff seeding can be repeated without duplicating or resetting accounts', function () {
    $this->seed(StaffSeeder::class);

    $owner = User::query()->where('email', 'owner@printsync.test')->firstOrFail();
    $owner->forceFill(['name' => 'Renamed Owner'])->save();

    $this->seed(StaffSeeder::class);

    expect(User::query()->count())->toBe(15)
        ->and(StaffSpecialty::query()->count())->toBe(12)
        ->and($owner->fresh()->name)->toBe('Renamed Owner');
});
