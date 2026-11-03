<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\Agency;
use App\Models\User;
use App\Services\RoadToSold\AgentLevelService;
use Illuminate\Console\Command;

/**
 * AT-454 v2 phase 4 - the nightly level check (road-to-sold.md section 8.1).
 *
 * For every agent or candidate who can use Road to Sold: when they have been on target for the number of weeks the agency asks for,
 * put them forward for sign-off (their managers are told) - or, where the agency does not ask for a sign-off, move them up. Completing
 * tasks never promotes by itself. Safe to re-run: an agent already waiting is not asked about twice.
 *
 *   rts:check-levels                  every agency
 *   rts:check-levels --agency=1       one agency
 *   rts:check-levels --user=22        one agent
 *   rts:check-levels --dry-run        say who WOULD be put forward; write nothing
 *
 * One agent failing never stops the rest.
 */
class CheckLevels extends Command
{
    protected $signature = 'rts:check-levels
                            {--agency= : Only this agency id}
                            {--user= : Only this user id}
                            {--track=sales : sales (rentals arrive with Road to Rented)}
                            {--dry-run : Report only, write nothing}';

    protected $description = 'Road to Sold: put agents who have met their level for the required weeks forward for sign-off';

    public function handle(AgentLevelService $levels): int
    {
        $track = (string) $this->option('track');
        if (! in_array($track, \App\Models\RoadToSold\RtsSetting::TRACKS, true)) {
            $this->error("Unknown track '{$track}'. Use: " . implode(', ', \App\Models\RoadToSold\RtsSetting::TRACKS) . '.');

            return self::FAILURE;
        }

        if ($this->option('dry-run') || $this->option('user')) {
            return $this->single($levels, $track);
        }

        $agencies = Agency::query()->withoutGlobalScopes()
            ->when($this->option('agency'), fn ($q, $a) => $q->where('id', (int) $a))->orderBy('id')->pluck('id');
        $total = ['requested' => 0, 'advanced' => 0, 'checked' => 0];
        foreach ($agencies as $agencyId) {
            $c = $levels->checkAgency((int) $agencyId, $track);
            foreach ($c as $k => $v) {
                $total[$k] += $v;
            }
        }
        $this->info("[WRITE] rts:check-levels - {$total['checked']} checked, {$total['requested']} put forward, {$total['advanced']} moved up.");

        return self::SUCCESS;
    }

    /** --dry-run and --user: go agent by agent so the answer can say why. */
    private function single(AgentLevelService $levels, string $track): int
    {
        $dry = (bool) $this->option('dry-run');
        $users = User::withoutGlobalScopes()->whereNull('deleted_at')->where('is_active', true)->whereNotNull('agency_id')
            ->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))
            ->when($this->option('user'), fn ($q, $u) => $q->where('id', (int) $u))
            ->orderBy('agency_id')->orderBy('id')->get();

        $rows = [];
        foreach ($users as $user) {
            if (! $levels->eligible($user)) {
                continue;
            }
            $p = $levels->progress($user, $track);
            $rows[] = [$user->agency_id, $user->id, $user->name, $p['level']?->name ?? '-', $p['required'] === [] ? 'no required task' : $p['streak'] . ' of ' . $p['needed'],
                $dry ? ($p['status'] === 'active' && $p['required'] !== [] && $p['streak'] >= $p['needed'] ? 'WOULD be put forward' : $p['status']) : ($levels->check($user, $track) ?? $p['status'])];
        }
        $this->table(['Agency', 'User', 'Name', 'Level', 'Weeks on target', $dry ? 'Result (nothing written)' : 'Result'], $rows);

        return self::SUCCESS;
    }
}
