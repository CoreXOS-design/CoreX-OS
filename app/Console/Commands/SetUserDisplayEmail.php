<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * AT-79 — set (or clear) a user's outward-facing email override.
 *
 * Generic + configurable: takes the login email and the display email as
 * arguments — no addresses are hardcoded anywhere in the codebase. Pass an
 * empty display email (or --clear) to remove the override.
 *
 *   php artisan users:set-display-email login@example.com display@example.com
 *   php artisan users:set-display-email login@example.com --clear
 */
class SetUserDisplayEmail extends Command
{
    protected $signature = 'users:set-display-email
        {email : The user\'s real login email}
        {display_email? : The outward-facing email to show (omit with --clear to remove)}
        {--clear : Clear the override (display_email = null)}';

    protected $description = 'Set or clear a user\'s outward-facing display_email override (AT-79)';

    public function handle(): int
    {
        $login   = trim((string) $this->argument('email'));
        $clear   = (bool) $this->option('clear');
        $display = $clear ? null : trim((string) $this->argument('display_email'));

        if (!$clear && ($display === null || $display === '')) {
            $this->error('Provide a display_email, or pass --clear to remove the override.');
            return self::INVALID;
        }
        if (!$clear && !filter_var($display, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email: {$display}");
            return self::INVALID;
        }

        // Match across all agencies/branches (console has no tenant context;
        // drop ALL global scopes so agency + branch + any visibility scope
        // can't hide the target user).
        $user = User::withoutGlobalScopes()
            ->where('email', $login)
            ->first();

        if (!$user) {
            $this->error("No user found with login email: {$login}");
            return self::FAILURE;
        }

        $user->display_email = $display;
        $user->save();

        $this->info($clear
            ? "Cleared display_email for {$login} (now falls back to the login email)."
            : "Set display_email for {$login} → {$display}.");

        return self::SUCCESS;
    }
}
