<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\CoreXPermission;
use App\Services\Assistants\AssistantMatrixSnapshotService;
use App\Services\Assistants\AssistantPermissionResolver;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-267 — the agent's own page: what may my assistant do?
 *
 * Gated by OWNERSHIP, not by a permission key. The right to configure your own assistant
 * derives from being their Assigned Agent — the same way "edit my own profile" derives from
 * being that user. There is deliberately no `assistants.manage-own` key: making it grantable
 * would create the nonsense state of an agent who has an assistant but cannot configure them,
 * leaving the assistant stuck on a matrix nobody but an admin can fix.
 *
 * The agent can ONLY edit the matrix here. Renaming, reassigning and revoking are admin
 * actions — an agent must not be able to quietly move or delete a person's system access.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §8, §12
 */
class AssistantMatrixController extends Controller
{
    public function __construct(private readonly AssistantMatrixSnapshotService $snapshots)
    {
    }

    public function index(Request $request)
    {
        $agent = $request->user();

        $assignments = AssistantAssignment::active()
            ->where('agent_user_id', $agent->id)
            ->with(['assistant', 'permissions'])
            ->get();

        // Nothing to manage → the page does not exist for you. (The sidebar entry is
        // conditional on the same fact, so this is only reachable by typing the URL.)
        abort_if($assignments->isEmpty(), 404);

        return view('agent.assistants.index', [
            'assignments' => $assignments->map(fn (AssistantAssignment $a) => [
                'model'         => $a,
                'grantedCount'  => $a->permissions->where('granted', true)->where('is_locked', false)->count(),
                'pendingDrift'  => $this->snapshots->pendingDriftCount($a),
            ]),
        ]);
    }

    public function edit(Request $request, AssistantAssignment $assignment)
    {
        $this->authorizeAgent($request, $assignment);

        $agent = $request->user();

        // Top up the matrix with anything the agent has GAINED since it was seeded. The new
        // rows arrive switched OFF (D6) — the agent decides, here, whether to hand them over.
        $this->snapshots->syncDrift($assignment);

        $assignment->load('permissions');

        return view('agent.assistants.matrix', [
            'assignment'   => $assignment,
            'assistant'    => $assignment->assistant,
            'sections'     => $this->matrixSections($agent, $assignment),
            'pendingDrift' => $this->snapshots->pendingDriftCount($assignment),
        ]);
    }

    public function save(Request $request, AssistantAssignment $assignment)
    {
        $this->authorizeAgent($request, $assignment);

        $agent = $request->user();

        $submitted = (array) $request->input('permissions', []);
        $scopes    = (array) $request->input('scopes', []);

        // What the AGENT actually holds, right now. Anything outside this set is discarded
        // silently rather than trusted — an agent cannot hand over a permission they do not
        // have, and a crafted POST must not be able to write one into the matrix. (The
        // resolver would deny it at read time anyway, but a matrix that CLAIMS to grant
        // something the agent lacks is a lie sitting in the database, and someone will read it
        // one day and believe it.)
        $agentHolds = array_flip(array_filter(
            CoreXPermission::query()->pluck('key')->all(),
            fn (string $key) => $agent->hasPermission($key)
        ));

        DB::transaction(function () use ($assignment, $submitted, $scopes, $agentHolds, $agent) {
            foreach ($assignment->permissions()->get() as $row) {
                // Layer 4 of the property-upload lock. A locked row is never granted, whatever
                // arrives in the payload. (The model's saving() hook enforces it as well.)
                if ($row->is_locked || AssistantPermissionResolver::isLocked($row->permission_key)) {
                    $row->forceFill(['granted' => false, 'scope' => null, 'is_locked' => true])->save();

                    continue;
                }

                $wants = (string) ($submitted[$row->permission_key] ?? '0') === '1';

                if ($wants && !isset($agentHolds[$row->permission_key])) {
                    $wants = false; // the agent does not hold it — they cannot give it
                }

                $scope = null;

                if ($wants && str_ends_with($row->permission_key, '.view')) {
                    $module      = substr($row->permission_key, 0, -strlen('.view'));
                    $agentScope  = PermissionService::getDataScope($agent, $module);
                    $wantedScope = $scopes[$row->permission_key] ?? null;

                    if ($wantedScope === 'none' || $wantedScope === null) {
                        $wants = false;
                    } else {
                        // Never wider than the agent's own breadth.
                        $scope = PermissionService::clampScope($wantedScope, $agentScope ?? 'own');
                    }
                }

                $row->forceFill(['granted' => $wants, 'scope' => $scope])->save();
            }
        });

        // AT-267 V2 — the behaviour panel toggles (ownership is always the agent; these only
        // control edit/delete, attribution and notifications). Guarded per BUILD_STANDARD §6.1:
        // only write when the panel was actually posted, so a permissions-only save can never
        // silently wipe a setting the request never rendered. The panel posts a hidden "0"
        // companion for every toggle, so an unchecked box arrives as '0', not absent.
        if ($request->has('settings')) {
            $settings = (array) $request->input('settings', []);
            $assignment->forceFill([
                'can_manage_my_records' => (string) ($settings['can_manage_my_records'] ?? '0') === '1',
                'show_attribution'      => (string) ($settings['show_attribution'] ?? '0') === '1',
                'notify_on_action'      => (string) ($settings['notify_on_action'] ?? '0') === '1',
            ])->save();
        }

        // The assistant's effective permissions are recomputed from scratch on their next
        // request — nothing to invalidate but the request-local cache.
        PermissionService::clearCache();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Saved. ' . $assignment->assistant->name . "'s access has been updated.",
            ]);
        }

        return back()->with('success', 'Saved. ' . $assignment->assistant->name . "'s access has been updated.");
    }

    /**
     * The matrix, grouped for the UI — and filtered to ONLY what the agent themselves holds.
     *
     * An agent literally cannot see, let alone grant, a permission they do not have. The
     * locked property-upload rows ARE shown (disabled, with the reason) rather than omitted:
     * "No Silent Locks" — an agent looking for "why can't my assistant upload a listing?"
     * must find the answer on the page, not conclude the feature is broken.
     */
    private function matrixSections($agent, AssistantAssignment $assignment): array
    {
        $rows = $assignment->permissions->keyBy('permission_key');

        $definitions = CoreXPermission::query()
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get();

        $sections = [];

        foreach ($definitions as $def) {
            $locked   = AssistantPermissionResolver::isLocked($def->key);
            $agentHas = $agent->hasPermission($def->key);

            // Not the agent's to give, and not a lock worth explaining → not on the page.
            if (!$agentHas && !$locked) {
                continue;
            }

            $row = $rows->get($def->key);

            $scopeCeiling = null;

            if (str_ends_with($def->key, '.view')) {
                $module       = substr($def->key, 0, -strlen('.view'));
                $scopeCeiling = PermissionService::getDataScope($agent, $module);
            }

            $sections[$def->section][] = [
                'key'           => $def->key,
                'label'         => $def->label,
                'is_locked'     => $locked,
                'granted'       => (bool) ($row?->granted),
                'scope'         => $row?->scope,
                'scope_ceiling' => $scopeCeiling,
                'is_view'       => str_ends_with($def->key, '.view'),
                'is_new'        => $row && !$row->granted && !$locked,
            ];
        }

        return $sections;
    }

    private function authorizeAgent(Request $request, AssistantAssignment $assignment): void
    {
        abort_unless(
            (int) $assignment->agent_user_id === (int) $request->user()->id,
            403,
            'You can only manage your own assistant.'
        );

        abort_unless($assignment->isActive(), 404);
    }
}
