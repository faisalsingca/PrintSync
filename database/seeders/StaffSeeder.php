<?php

namespace Database\Seeders;

use App\Models\StaffSpecialty;
use App\Models\User;
use App\StaffSpecialtyType;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /**
     * Shared development password for every seeded account.
     */
    public const PASSWORD = 'printsync123';

    /**
     * Two staff members for every staff specialty.
     *
     * @var array<string, list<array{name: string, email: string}>>
     */
    private const STAFF = [
        'graphic_designer' => [
            ['name' => 'Ana Reyes', 'email' => 'designer1@printsync.test'],
            ['name' => 'Marco Villanueva', 'email' => 'designer2@printsync.test'],
        ],
        'book_binder' => [
            ['name' => 'Liza Santos', 'email' => 'binder1@printsync.test'],
            ['name' => 'Ramon Cruz', 'email' => 'binder2@printsync.test'],
        ],
        'sewer' => [
            ['name' => 'Nena Bautista', 'email' => 'sewer1@printsync.test'],
            ['name' => 'Joel Mendoza', 'email' => 'sewer2@printsync.test'],
        ],
        'tarpaulin_editor' => [
            ['name' => 'Carlo Dizon', 'email' => 'tarpaulin1@printsync.test'],
            ['name' => 'Paolo Navarro', 'email' => 'tarpaulin2@printsync.test'],
        ],
        'sticker_editor' => [
            ['name' => 'Mia Torres', 'email' => 'sticker1@printsync.test'],
            ['name' => 'Jerome Aquino', 'email' => 'sticker2@printsync.test'],
        ],
        'machine_operator' => [
            ['name' => 'Rico Flores', 'email' => 'operator1@printsync.test'],
            ['name' => 'Dennis Garcia', 'email' => 'operator2@printsync.test'],
        ],
    ];

    /**
     * @var list<array{name: string, email: string}>
     */
    private const CASHIERS = [
        ['name' => 'Clara Lim', 'email' => 'cashier1@printsync.test'],
        ['name' => 'Victor Tan', 'email' => 'cashier2@printsync.test'],
    ];

    /**
     * Run the database seeds.
     *
     * Safe to repeat: accounts are matched by email, existing accounts are not
     * changed, and nothing is deleted.
     */
    public function run(): void
    {
        foreach (self::STAFF as $specialty => $members) {
            // Fails loudly if the specialty is not a documented enum value.
            $specialty = StaffSpecialtyType::from($specialty)->value;

            foreach ($members as $member) {
                $staff = $this->account($member, 'staff');

                StaffSpecialty::query()->firstOrCreate([
                    'staff_id' => $staff->id,
                    'specialty' => $specialty,
                ]);
            }
        }

        foreach (self::CASHIERS as $cashier) {
            $this->account($cashier, 'cashier');
        }

        $this->account(['name' => 'RPG Print Owner', 'email' => 'owner@printsync.test'], 'owner');
    }

    /**
     * @param  array{name: string, email: string}  $details
     */
    private function account(array $details, string $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $details['email']],
            [
                'name' => $details['name'],
                'password' => self::PASSWORD,
                'role' => $role,
            ],
        );

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
