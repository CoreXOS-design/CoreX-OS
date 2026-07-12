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
 * GATING: invoked from the local/demo environment gate in DatabaseSeeder, and
 * from DemoDataSeeder::run() (which is itself behind the demo:seed double-lock
 * environment + protected-database gates) so that the documented demo rebuild —
 * `migrate:fresh --database=demo && demo:seed`, which never runs DatabaseSeeder
 * — restores this account too. It must never run on staging/production: a
 * standing privileged credential on the live system is exactly what we don't
 * want, and the System Owner login route is itself demo-mode-only anyway.
 */
class SystemOwnerSeeder extends Seeder
{
    use WithoutModelEvents;

    public const EMAIL = 'Demo@corexos.co.za';
    public const PASSWORD = 'Demo@1024';

    public function run(): void
    {
        // COLLISION GUARD. `users.email` is utf8mb4_unicode_ci — case-
        // INSENSITIVE — under a UNIQUE index, so self::EMAIL ('Demo@…') and a
        // tenant login differing only in case ('demo@…') are THE SAME ROW to
        // MySQL. Without this guard the updateOrCreate below matches that
        // tenant user and silently rewrites it into the platform owner —
        // nulling its agency_id and detaching every record it owns. That is
        // precisely what DemoDataSeeder's old 'demo@corexos.co.za' admin would
        // have done. Fail loudly instead of corrupting data: the owner is the
        // only account allowed to hold this address in any casing.
        $existing = User::where('email', self::EMAIL)->first();

        if ($existing && $existing->agency_id !== null) {
            throw new \RuntimeException(
                "Refusing to seed the System Owner: user #{$existing->id} ({$existing->email}) "
                . "already holds this address case-insensitively and belongs to agency "
                . "#{$existing->agency_id}. Overwriting it would detach that agency's data. "
                . "Give that user a different email — " . self::EMAIL . " is reserved for the "
                . "platform owner."
            );
        }

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
