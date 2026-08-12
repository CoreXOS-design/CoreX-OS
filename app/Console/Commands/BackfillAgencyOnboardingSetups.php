<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\User;
use App\Services\Onboarding\AgencyAdminFirstLoginService;
use Illuminate\Console\Command;

/**
 * Backfill an AgencyOnboardingSetup record for every existing LIVE agency that
 * doesn't have one yet.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §4.1.
 *
 * Agencies created BEFORE this feature never fired AgencyCreated, so they have
 * no setup record — meaning they never appear on the owner tracking board and
 * their admins never see the "Continue setup" nudge. This command creates the
 * missing records (idempotent), linking each to its agency Admin.
 *
 * By default it does NOT email anyone — existing agencies are already operating,
 * so a surprise blast would be wrong. Pass --email to send the guided-setup link
 * to freshly-created backfill records (never to ones that already existed).
 *
 * Demo agencies are skipped (they have no Admin and no wizard).
 * Runs on deploy via the backfill migration, and is safe to re-run any time.
 */
class BackfillAgencyOnboardingSetups extends Command
{
    protected $signature = 'agency:backfill-onboarding-setups
        {--email : Also email the setup link to newly-created backfill records}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Create AgencyOnboardingSetup records for existing live agencies that lack one';

    public function handle(AgencyAdminFirstLoginService $onboardingMail): int
    {
        $email = (bool) $this->option('email');
        $dry   = (bool) $this->option('dry-run');

        // Every live (non-demo) agency, regardless of the caller's tenant scope.
        $agencies = Agency::query()
            ->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'))
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $emailed = 0;

        foreach ($agencies as $agency) {
            $exists = AgencyOnboardingSetup::queryWithoutAgencyScope()
                ->where('agency_id', $agency->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            // Link the agency's Admin (first active admin-role user) so the
            // login gate + tracking board have a subject. Owners are platform
            // identities, never agency members, so they are not eligible.
            $admin = User::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('role', 'admin')
                ->orderBy('id')
                ->first();

            $this->line(sprintf(
                '  %s agency #%d "%s"%s',
                $dry ? '[dry] would create for' : 'creating for',
                $agency->id,
                $agency->name,
                $admin ? " → admin {$admin->email}" : ' (no admin found)'
            ));

            if ($dry) {
                $created++;
                continue;
            }

            $setup = new AgencyOnboardingSetup();
            $setup->agency_id       = $agency->id;
            $setup->token           = AgencyOnboardingSetup::generateToken();
            $setup->slug            = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
            $setup->created_by      = null; // system backfill, no human actor
            $setup->admin_user_id   = $admin?->id;
            $setup->current_step    = 1;
            $setup->completed_steps = [];
            $setup->expires_at      = now()->addDays(30);
            $setup->save();
            $created++;

            if ($email && $admin?->email) {
                // Found in review 2026-08-12: this used to send without stamping
                // invite_email_sent_at, so the SAME admin's later first login
                // would find the setup still "unsent" and email them a second
                // time. Stamp on success, exactly like every other send path.
                if ($onboardingMail->sendMail($setup, $admin->email)) {
                    $setup->forceFill(['invite_email_sent_at' => now()])->save();
                    $emailed++;
                }
            }
        }

        $this->info(sprintf(
            'Agency onboarding backfill %s— created: %d, already had one: %d%s.',
            $dry ? '(dry run) ' : '',
            $created,
            $skipped,
            $email ? ", emailed: {$emailed}" : ''
        ));

        return self::SUCCESS;
    }
}
