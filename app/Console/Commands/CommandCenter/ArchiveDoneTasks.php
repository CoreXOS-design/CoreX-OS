<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\Agency;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use Illuminate\Console\Command;

class ArchiveDoneTasks extends Command
{
    protected $signature = 'command-center:archive-done-tasks {--dry-run : Do not delete, just report}';
    protected $description = 'Auto-archive Done tasks per each user/agency auto_archive_done_days setting';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $archived = 0;
        $scanned  = 0;

        // Iterate every active user; resolve effective setting; archive Done tasks that have been done
        // longer than the configured window.
        User::query()->whereNull('deleted_at')->chunk(200, function ($users) use ($dryRun, &$archived, &$scanned) {
            foreach ($users as $user) {
                $days = $this->resolveAutoArchiveDays($user);
                if ($days === null) {
                    continue; // user has auto-archive disabled
                }

                $cutoff = now()->subDays($days);

                $q = CommandTask::query()
                    ->where('assigned_to', $user->id)
                    ->where('status', CommandTask::STATUS_DONE)
                    ->where(function ($q2) use ($cutoff) {
                        // use completed_at when present, else fall back to updated_at
                        $q2->where('completed_at', '<=', $cutoff)
                           ->orWhere(function ($q3) use ($cutoff) {
                               $q3->whereNull('completed_at')->where('updated_at', '<=', $cutoff);
                           });
                    });

                $scanned += $q->count();

                if ($dryRun) {
                    continue;
                }

                $q->get()->each(function ($task) use (&$archived) {
                    $task->delete();
                    $archived++;
                });
            }
        });

        $this->info($dryRun
            ? "Dry run: {$scanned} task(s) would be archived."
            : "Archived {$archived} done task(s) (scanned {$scanned})."
        );

        return self::SUCCESS;
    }

    /**
     * Resolve auto_archive_done_days for a user — agency override wins when agency mode = 'agency'.
     */
    protected function resolveAutoArchiveDays(User $user): ?int
    {
        $agencyId = $user->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : null;

        if ($agency && ($agency->dashboard_settings_mode ?? 'user') === 'agency') {
            $a = AgencyDashboardSetting::where('agency_id', $agency->id)->first();
            return $a?->auto_archive_done_days === null ? null : (int) $a->auto_archive_done_days;
        }

        $u = UserDashboardSetting::where('user_id', $user->id)->first();
        return $u?->auto_archive_done_days === null ? null : (int) $u->auto_archive_done_days;
    }
}
