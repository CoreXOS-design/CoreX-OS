<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Permanent System Owner login for demo / local environments.
 *
 * The demo box is wiped and reseeded constantly. The "System Owner" sidebar
 * entry (shown when demo mode is on) posts to DemoOwnerLoginController, which
 * authenticates a real owner-role user — but a wipe would leave no such user,
 * breaking the login. This seeder guarantees a known-good owner account exists
 * after every reseed.
 *
 * Idempotent: updateOrCreate on the email, and it always resets the password +
 * role so a reset restores the documented credentials.
 *
 * GATING: invoked only inside the local/demo environment gate in
 * DatabaseSeeder. It must never run on staging/production — a standing
 * privileged credential on the live system is exactly what we don't want, and
 * the System Owner login route is itself demo-mode-only anyway.
 */
class SystemOwnerSeeder extends Seeder
{
    use WithoutModelEvents;

    public const EMAIL = 'Demo@corexos.co.za';
    public const PASSWORD = 'Demo@1024';

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name'              => 'System Owner',
                'password'          => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role'              => 'super_admin', // is_owner role — see roles seeder
                'agency_id'         => null,          // platform identity, not a tenant member
                'branch_id'         => null,
                'is_active'         => true,
            ]
        );
    }
}
