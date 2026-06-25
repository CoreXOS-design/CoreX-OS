<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * READ-ONLY diagnostic for "half the agents' info/photos don't reach P24".
 *
 * For every agent it reports the two facts that decide whether a P24 sync can
 * possibly succeed, mirroring exactly what Property24SyndicationService does:
 *
 *   1. Resolved P24 agency ID — branch->resolveP24AgencyId(), then the user's
 *      agency p24_agency_id. NULL here means the agent can NEVER sync (title or
 *      photo): resolveAgencyIdForUser() bails before any P24 call.
 *   2. Profile photo source — a user_documents 'profile_photo' row first, then
 *      the legacy agent_photo_path column (same priority as profilePhotoUrl()),
 *      plus whether that file actually exists on the public disk. No file = no
 *      photo on P24, even on a manual refresh.
 *
 * With --remote it additionally fetches each agency's P24 agent list once and
 * marks whether the agent is registered there (matched by sourceReference
 * 'CoreX-Agent-<id>'). Nothing is written anywhere — safe to run on production.
 */
class P24AgentAudit extends Command
{
    protected $signature = 'p24:agent-audit
        {--agency=0 : Limit to one CoreX agency ID (0 = all agencies)}
        {--role= : Limit to a single role (e.g. agent); empty = all roles}
        {--only-failing : Only list agents that cannot fully sync}
        {--remote : Also query P24 to confirm each agent is registered (slower)}';

    protected $description = 'READ-ONLY: report why agents do/don\'t sync to Property24 (P24 agency ID + photo presence)';

    public function handle(): int
    {
        $agencyOpt = (int) $this->option('agency');
        $role      = trim((string) $this->option('role'));

        $query = User::withoutGlobalScope(AgencyScope::class)
            ->orderBy('agency_id')
            ->orderBy('name');
        if ($agencyOpt > 0) {
            $query->where('agency_id', $agencyOpt);
        }
        if ($role !== '') {
            $query->where('role', $role);
        }
        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        // --remote: build a sourceReference set per distinct P24 agency, fetched
        // once. The ApiClient memoizes getAgents per agency for the run.
        $remoteRefs = [];   // [p24AgencyId => [sourceReference => p24AgentId]]
        if ($this->option('remote')) {
            $remoteRefs = $this->fetchRemoteRefs($users);
        }

        $rows = [];
        $noAgency = 0;
        $noPhoto = 0;
        $notRegistered = 0;
        $ok = 0;

        foreach ($users as $user) {
            $p24AgencyId = $this->resolveAgencyIdForUser($user);
            [$photoSource, $photoExists] = $this->resolvePhoto($user);

            $agencyCell = $p24AgencyId !== null ? (string) $p24AgencyId : 'NONE';
            $photoCell  = $photoSource === null
                ? 'none'
                : ($photoExists ? $photoSource : "{$photoSource} (FILE MISSING)");

            $registeredCell = '—';
            $isRegistered = null;
            if ($this->option('remote') && $p24AgencyId !== null) {
                $ref = 'CoreX-Agent-' . $user->id;
                $map = $remoteRefs[(string) $p24AgencyId] ?? null;
                if ($map === null) {
                    $registeredCell = 'lookup failed';
                } else {
                    $isRegistered = array_key_exists($ref, $map);
                    $registeredCell = $isRegistered ? ('yes #' . $map[$ref]) : 'NO';
                }
            }

            $canSyncTitle = $p24AgencyId !== null;
            $canSyncPhoto = $canSyncTitle && $photoSource !== null && $photoExists;
            $failing = !$canSyncTitle || !$canSyncPhoto || ($isRegistered === false);

            if ($p24AgencyId === null) $noAgency++;
            if ($canSyncTitle && ($photoSource === null || !$photoExists)) $noPhoto++;
            if ($isRegistered === false) $notRegistered++;
            if (!$failing) $ok++;

            if ($this->option('only-failing') && !$failing) {
                continue;
            }

            $rows[] = [
                $user->id,
                \Illuminate\Support\Str::limit($user->name, 24),
                $user->role ?? '',
                $user->designation ?: '—',
                $agencyCell,
                $photoCell,
                $registeredCell,
            ];
        }

        $this->table(
            ['ID', 'Name', 'Role', 'Title (designation)', 'P24 Agency', 'Photo source', 'On P24'],
            $rows
        );

        $total = $users->count();
        $this->newLine();
        $this->info("Audited {$total} user(s).");
        $this->line("  Cannot sync at all (no P24 agency ID on branch/agency): <fg=red>{$noAgency}</>");
        $this->line("  Have a P24 agency but no usable photo file:             <fg=yellow>{$noPhoto}</>");
        if ($this->option('remote')) {
            $this->line("  Have a P24 agency but NOT registered on P24:            <fg=yellow>{$notRegistered}</>");
        }
        $this->line("  Fully syncable:                                         <fg=green>{$ok}</>");

        if ($noAgency > 0) {
            $this->newLine();
            $this->warn("Fix the red bucket first: set p24_agency_id on those agents' branch (Admin → Branches) or agency. Until then no amount of \"Sync to P24\" will move their data.");
        }

        return self::SUCCESS;
    }

    /**
     * Mirror Property24SyndicationService::resolveAgencyIdForUser (private there).
     */
    private function resolveAgencyIdForUser(User $user): ?int
    {
        $branchId = $user->branch_id ? (int) $user->branch_id : null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            if ($branch) {
                $resolved = $branch->resolveP24AgencyId();
                if ($resolved !== null && $resolved !== '') {
                    return (int) $resolved;
                }
            }
        }
        $agency = $user->agency;
        if ($agency && !empty($agency->p24_agency_id)) {
            return (int) $agency->p24_agency_id;
        }
        return null;
    }

    /**
     * Mirror the photo resolution used by the sync (user_documents profile_photo
     * first, then legacy agent_photo_path). Returns [source label, file exists?].
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolvePhoto(User $user): array
    {
        $doc = $user->documents()
            ->where('document_type', 'profile_photo')
            ->latest()
            ->first();
        if ($doc && !empty($doc->file_path)) {
            return ['user_documents', Storage::disk('public')->exists($doc->file_path)];
        }
        if (!empty($user->agent_photo_path)) {
            return ['agent_photo_path', Storage::disk('public')->exists($user->agent_photo_path)];
        }
        return [null, false];
    }

    /**
     * Fetch the P24 agent list once per distinct P24 agency and return a map of
     * [p24AgencyId => [sourceReference => p24AgentId]]. Best-effort — a failed
     * lookup leaves that agency unmapped (reported as "lookup failed").
     *
     * @return array<string, array<string, int>>
     */
    private function fetchRemoteRefs($users): array
    {
        $out = [];
        // Group resolvable users by CoreX agency so we build one ApiClient per
        // agency (carries that agency's P24 credentials), then fetch each
        // distinct resolved P24 agency ID.
        $byAgency = [];
        foreach ($users as $user) {
            $p24AgencyId = $this->resolveAgencyIdForUser($user);
            if ($p24AgencyId === null) {
                continue;
            }
            $byAgency[(int) $user->agency_id][(string) $p24AgencyId] = true;
        }

        foreach ($byAgency as $agencyId => $p24Ids) {
            $agency = Agency::find($agencyId);
            $client = new Property24ApiClient($agency);
            foreach (array_keys($p24Ids) as $p24AgencyId) {
                if (isset($out[$p24AgencyId])) {
                    continue;
                }
                $result = $client->getAgents($p24AgencyId);
                if (!($result['success'] ?? false)) {
                    $this->warn("getAgents failed for P24 agency {$p24AgencyId}: " . ($result['message'] ?? 'unknown'));
                    continue;
                }
                $map = [];
                foreach ($result['data'] ?? [] as $agent) {
                    $ref = $agent['sourceReference'] ?? '';
                    if ($ref !== '') {
                        $map[$ref] = (int) ($agent['id'] ?? 0);
                    }
                }
                $out[$p24AgencyId] = $map;
            }
        }

        return $out;
    }
}
