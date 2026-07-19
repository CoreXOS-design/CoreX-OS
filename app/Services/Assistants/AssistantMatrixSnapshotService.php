<?php

namespace App\Services\Assistants;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\CoreXPermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;

/**
 * AT-267 — seeding and maintaining an assistant's permission matrix.
 *
 * TWO DIFFERENT MOMENTS, TWO DIFFERENT DEFAULTS. This is Johan's ruling (D6) and the
 * distinction is the whole design:
 *
 *   snapshot()  — at ASSIGNMENT. The matrix is a COPY of the agent's permissions, all
 *                 switched ON, minus the property-upload locked set. The assistant is
 *                 immediately useful; the agent switches OFF what they don't want to hand
 *                 over. Starting from an empty matrix would mean every new assistant lands
 *                 able to do nothing, and the agent has to go tick forty boxes before the
 *                 person they hired can work — which is how a feature ends up unused.
 *
 *   syncDrift() — LATER, when the agent GAINS a permission they didn't have. The new row is
 *                 added switched OFF, and the agent's Assistant page shows "N new permissions
 *                 available". A capability nobody has ever consciously handed over is not
 *                 handed over silently. The assistant follows the agent's CEILING
 *                 automatically (that is the resolver's live intersection), but a genuinely
 *                 new capability is always a decision.
 *
 * Note what is NOT here: nothing removes rows when the agent LOSES a permission. It would be
 * wrong to. The resolver already denies it live, so the assistant loses it on the next request
 * regardless of what the matrix says — and keeping the row means that if the agent gets the
 * permission back, the assistant's setting is exactly as the agent left it, with nobody having
 * to re-tick anything.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §4, §14 E4, §16 Prompt G
 */
class AssistantMatrixSnapshotService
{
    /**
     * Seed a fresh matrix from the Assigned Agent's current permissions.
     *
     * Every permission the agent holds → granted = true (the copy).
     * Every key in the property-upload locked set → granted = false, is_locked = true, and
     * the UI renders it disabled. Locked rows are written EVEN IF the agent holds the key —
     * that is the point of them.
     *
     * Idempotent: safe to re-run. Existing rows keep the agent's choices; only missing keys
     * are added.
     */
    public function snapshot(AssistantAssignment $assignment): int
    {
        $agent = $assignment->assignedAgent;

        if (!$agent) {
            return 0;
        }

        return $this->seed($assignment, $agent, grantedByDefault: true);
    }

    /**
     * Add any permission the agent has GAINED since the matrix was last seeded — switched OFF.
     *
     * Returns the number of newly-available permissions, which is what the "N new permissions
     * available" chip on the agent's Assistant page counts.
     */
    public function syncDrift(AssistantAssignment $assignment): int
    {
        $agent = $assignment->assignedAgent;

        if (!$agent) {
            return 0;
        }

        return $this->seed($assignment, $agent, grantedByDefault: false);
    }

    /**
     * How many rows are sitting available-but-off because of drift — i.e. the agent gained
     * something and has not yet decided whether to hand it over.
     */
    public function pendingDriftCount(AssistantAssignment $assignment): int
    {
        return $assignment->permissions()
            ->where('granted', false)
            ->where('is_locked', false)
            ->count();
    }

    /**
     * The keys an assistant's matrix should contain: everything their agent actually holds,
     * plus the locked set (which is always present, always off, so the UI can SHOW the lock
     * and explain it rather than silently omitting the row).
     *
     * @return string[]
     */
    public function keysFor(User $agent): array
    {
        $allKeys = CoreXPermission::query()->pluck('key')->all();

        $agentHolds = array_values(array_filter(
            $allKeys,
            fn (string $key) => $agent->hasPermission($key)
        ));

        return array_values(array_unique(array_merge(
            $agentHolds,
            AssistantPermissionResolver::lockedSet(),
        )));
    }

    private function seed(AssistantAssignment $assignment, User $agent, bool $grantedByDefault): int
    {
        // The agent's own live permissions decide the matrix's shape. Resolve them against a
        // clean cache — a stale one here would seed a matrix from a permission set the agent
        // no longer has.
        PermissionService::clearCache();

        $keys = $this->keysFor($agent);

        $existing = $assignment->permissions()
            ->withTrashed()
            ->pluck('permission_key')
            ->all();

        $missing = array_values(array_diff($keys, $existing));

        if ($missing === []) {
            return 0;
        }

        // Admin-access keys default OFF on a fresh snapshot (Johan 2026-07-19). Only relevant
        // when grantedByDefault is true — a drift top-up already arrives OFF, so the set is
        // empty there and the lookup is skipped.
        $adminOff = $grantedByDefault ? $this->adminDefaultOffKeys() : [];

        $now  = now();
        $rows = [];

        foreach ($missing as $key) {
            $locked = AssistantPermissionResolver::isLocked($key);

            // A locked key is NEVER granted, whatever the default is (the model's saving() hook
            // enforces this too). An admin-access key defaults OFF but stays editable — the
            // agent can consciously turn it on, exactly like a drift row.
            $granted = $locked
                ? false
                : (isset($adminOff[$key]) ? false : $grantedByDefault);

            $rows[] = [
                'agency_id'               => $assignment->agency_id,
                'assistant_assignment_id' => $assignment->id,
                'permission_key'          => $key,
                'granted'                 => $granted,
                // Scope only carries meaning on a granted .view row; an off row (locked or
                // admin-default-off) stores null so the matrix editor opens showing the truth.
                'scope'                   => $granted ? $this->scopeFor($agent, $key, true) : null,
                'is_locked'               => $locked,
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        }

        DB::transaction(function () use ($rows, $assignment, $grantedByDefault) {
            foreach (array_chunk($rows, 500) as $chunk) {
                AssistantAssignmentPermission::insert($chunk);
            }

            // Only a real snapshot re-stamps the timestamp. A drift sync is a top-up, not a
            // new baseline — overwriting it would lose "when was this assistant set up".
            if ($grantedByDefault) {
                $assignment->forceFill(['snapshot_taken_at' => now()])->save();
            }
        });

        return count($rows);
    }

    /**
     * The scope to seed for a `.view` key: the agent's own. An assistant may never out-see
     * their agent, and the resolver clamps to the agent's scope at read time anyway — seeding
     * the agent's value just means the matrix editor opens showing the truth.
     */
    private function scopeFor(User $agent, string $key, bool $grantedByDefault): ?string
    {
        if (!str_ends_with($key, '.view') || !$grantedByDefault) {
            return null;
        }

        $module = substr($key, 0, -strlen('.view'));

        return PermissionService::getDataScope($agent, $module);
    }

    /**
     * The permission keys that default OFF on a fresh snapshot — admin-access features an
     * assistant must never inherit silently just because their agent is an admin (Johan
     * 2026-07-19). Derived from the catalogue's `section` so it tracks new admin permissions
     * automatically. Returned key => true for an O(1) membership test in seed().
     *
     * @return array<string, true>
     */
    private function adminDefaultOffKeys(): array
    {
        $sections = (array) config('assistants.admin_default_off_sections', []);

        if ($sections === []) {
            return [];
        }

        return CoreXPermission::query()
            ->whereIn('section', $sections)
            ->pluck('key')
            ->mapWithKeys(fn (string $key) => [$key => true])
            ->all();
    }
}
