<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@all-ecom.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ],
        );
    }
}
