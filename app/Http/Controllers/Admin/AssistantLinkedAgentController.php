<?php

namespace App\Http\Controllers\Admin;

use App\Events\Assistant\SubAgentLinked;
use App\Events\Assistant\SubAgentUnlinked;
use App\Http\Controllers\Controller;
use App\Models\AssistantAssignment;
use App\Models\AssistantLinkedAgent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * AT-267 multi-agent addendum — admin CRUD for a Sub-Agent link.
 *
 * A Sub-Agent link is data-breadth only: it grants zero permissions, has no matrix row, and
 * never touches the permission ceiling (which stays keyed to the assignment's Main Agent,
 * unchanged). Admin/super_admin manage this exclusively — not the Main Agent, not the Sub-Agent
 * (Johan's ruling, 2026-07-28, M2).
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §5, §7
 */
class AssistantLinkedAgentController extends Controller
{
    public function store(Request $request, AssistantAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.manage_linked_agents'), 403);

        $data = $request->validate([
            'agent_user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'agent_user_id.required' => 'Choose an agent to link.',
        ]);

        $candidate = $this->validateCandidate($assignment, (int) $data['agent_user_id']);

        AssistantLinkedAgent::create([
            'agency_id'               => $assignment->agency_id,
            'assistant_assignment_id' => $assignment->id,
            'agent_user_id'           => $candidate->id,
            'added_by_user_id'        => $request->user()->id,
        ]);

        event(new SubAgentLinked($assignment, $candidate->id, $request->user()->id));

        return redirect()
            ->route('admin.assistants.show', $assignment)
            ->with('success', "{$candidate->name} now also supports {$assignment->assistant->name}. "
                . "{$assignment->assistant->name} can see and edit {$candidate->name}'s own records, "
                . 'same as they already can for ' . $assignment->assignedAgent->name . '.');
    }

    public function destroy(Request $request, AssistantAssignment $assignment, AssistantLinkedAgent $linkedAgent)
    {
        abort_unless($request->user()->hasPermission('assistants.manage_linked_agents'), 403);
        abort_unless((int) $linkedAgent->assistant_assignment_id === (int) $assignment->id, 404);

        $subAgentId = (int) $linkedAgent->agent_user_id;
        $subAgentName = $linkedAgent->agent->name ?? 'That agent';

        $linkedAgent->forceFill([
            'removed_by_user_id' => $request->user()->id,
            'removed_at'         => now(),
        ])->save();
        $linkedAgent->delete();

        event(new SubAgentUnlinked($assignment, $subAgentId, $request->user()->id, null));

        return redirect()
            ->route('admin.assistants.show', $assignment)
            ->with('success', "{$subAgentName} has been unlinked. {$assignment->assistant->name} can no "
                . "longer see or edit {$subAgentName}'s records. This can be restored at any time.");
    }

    public function restore(Request $request, AssistantAssignment $assignment, int $linkedAgent)
    {
        abort_unless($request->user()->hasPermission('assistants.manage_linked_agents'), 403);

        $link = AssistantLinkedAgent::withTrashed()->findOrFail($linkedAgent);
        abort_unless((int) $link->assistant_assignment_id === (int) $assignment->id, 404);

        // Re-validate at restore time — the candidate may have since become an assistant,
        // an owner, or been deactivated since the link was removed (fail closed, not stale-trust).
        $candidate = User::findOrFail($link->agent_user_id);
        $this->validateCandidate($assignment, $candidate->id, allowExisting: true);

        $link->restore();
        $link->forceFill([
            'removed_by_user_id' => null,
            'removed_at'         => null,
        ])->save();

        event(new SubAgentLinked($assignment, $candidate->id, $request->user()->id));

        return redirect()
            ->route('admin.assistants.show', $assignment)
            ->with('success', "{$candidate->name} has been re-linked.");
    }

    // ── helpers ────────────────────────────────────────────────────

    private function validateCandidate(AssistantAssignment $assignment, int $agentId, bool $allowExisting = false): User
    {
        $pool = AssistantLinkedAgent::eligibleCandidates(
            $assignment,
            $allowExisting ? $agentId : null
        );

        $candidate = $pool->firstWhere('id', $agentId);

        if (!$candidate) {
            throw ValidationException::withMessages([
                'agent_user_id' => 'That person cannot be linked as a Sub-Agent. Owners, other '
                    . 'assistants, the Main Agent, deactivated users, and already-linked agents are not eligible.',
            ]);
        }

        return User::findOrFail($candidate->id);
    }
}
