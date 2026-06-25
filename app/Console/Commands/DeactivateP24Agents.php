<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;

/**
 * Deactivate (hide) specific agents on a P24 agency profile. P24 has NO
 * delete-agent endpoint, so the only way to remove an unwanted agent from the
 * portal is to PUT it Inactive + unpublished. Used to clean P24's pre-existing
 * agency roster (agents that came down in the import CSV but aren't HFC's).
 *
 * Safety:
 *   - operates ONLY on the explicit --ids list,
 *   - HARD-REFUSES any agent whose sourceReference is CoreX-Agent-* (a
 *     CoreX-managed agent must never be deactivated by this tool),
 *   - preview by default; pass --apply to actually write.
 */
class DeactivateP24Agents extends Command
{
    protected $signature = 'p24:deactivate-agents
        {--ids= : Comma-separated P24 agent ids to deactivate}
        {--agency= : CoreX agency id (defaults to the first with P24 credentials)}
        {--apply : Actually deactivate; without this it only previews}';

    protected $description = 'Deactivate (Inactive + unpublished) specific P24 agents — P24 has no delete endpoint';

    public function handle(): int
    {
        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Pass --ids=1,2,3 (P24 agent ids to deactivate).');
            return self::FAILURE;
        }

        $agency = $this->option('agency')
            ? Agency::find((int) $this->option('agency'))
            : Agency::whereNotNull('p24_username')->first();

        if (!$agency || empty($agency->p24_agency_id)) {
            $this->error('No agency with a P24 agency ID found.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $client = new Property24ApiClient($agency);
        $p24AgencyId = (string) $agency->p24_agency_id;

        $byId = collect($client->getAgents($p24AgencyId, true)['data'] ?? [])->keyBy('id');

        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $a = $byId[$id] ?? null;
            $name = $a ? trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')) : '?';

            if (!$a) {
                $this->warn("#{$id}: not found on P24 agency {$p24AgencyId} — skipped");
                $skipped++;
                continue;
            }

            // HARD GUARD: never deactivate a CoreX-managed agent.
            if (str_starts_with($a['sourceReference'] ?? '', 'CoreX-Agent-')) {
                $this->warn("#{$id} {$name}: CoreX-managed (sourceReference {$a['sourceReference']}) — REFUSED");
                $skipped++;
                continue;
            }

            if (!$apply) {
                $this->line("would deactivate #{$id} {$name} (ref " . ($a['sourceReference'] ?? '-') . ")");
                continue;
            }

            $payload = [
                'id'               => $id,
                'agencyId'         => (int) $p24AgencyId,
                'firstname'        => $a['firstname'] ?? '',
                'lastname'         => $a['lastname'] ?? '',
                'emailAddress'     => $a['emailAddress'] ?? '',
                'mobileNumber'     => $a['mobileNumber'] ?? '',
                'sourceReference'  => $a['sourceReference'] ?? '',
                'countryId'        => $a['countryId'] ?? 1,
                'published'        => false,
                'status'           => 'Inactive',
                'receiveStatsMail' => false,
            ];

            $r = $client->updateAgent($payload);
            if ($r['success'] ?? false) {
                $this->info("deactivated #{$id} {$name}");
                $done++;
            } else {
                $this->error("#{$id} {$name}: FAILED — " . ($r['message'] ?? 'unknown'));
                $skipped++;
            }
        }

        $this->newLine();
        if ($apply) {
            $this->info("Done: {$done} deactivated, {$skipped} skipped.");
        } else {
            $this->warn("Preview only — re-run with --apply to deactivate. {$skipped} would be skipped.");
        }

        return self::SUCCESS;
    }
}
