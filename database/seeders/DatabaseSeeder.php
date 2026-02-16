<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;   // ← ADD THIS LINE

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'Test@hfcoastal.co.za'],
            [
                'name' => 'Test User',
                'password' => Hash::make('Test@1024'),
                'email_verified_at' => now(),
            ]
        );
    }
}
