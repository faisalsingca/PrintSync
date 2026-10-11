<?php

use App\Models\StaffSpecialty;
use App\Models\User;
use App\StaffSpecialtyType;
use Database\Seeders\StaffSeeder;

test('staff seeding creates two staff per specialty plus cashiers and an owner', function () {
    $this->seed(StaffSeeder::class);

    foreach (StaffSpecialtyType::cases() as $specialty) {
        expect(StaffSpecialty::query()->where('specialty', $specialty->value)->count())
            ->toBe(2, "{$specialty->value} should have two staff");
    }

    expect(User::query()->where('role', 'staff')->count())->toBe(14)
        ->and(User::query()->where('role', 'cashier')->count())->toBe(2)
        ->and(User::query()->where('role', 'owner')->count())->toBe(1);
});

test('staff seeding can be repeated without duplicating or resetting accounts', function () {
    $this->seed(StaffSeeder::class);

    $owner = User::query()->where('email', 'owner@printsync.test')->firstOrFail();
    $owner->forceFill(['name' => 'Renamed Owner'])->save();

    $this->seed(StaffSeeder::class);

    expect(User::query()->count())->toBe(17)
        ->and(StaffSpecialty::query()->count())->toBe(14)
        ->and($owner->fresh()->name)->toBe('Renamed Owner');
});
