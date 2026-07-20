<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\UserInviteMail;
use App\Models\AssistantAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistants\AssistantMatrixSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AT-267 — the admin surface for Assistants.
 *
 * An admin creates the assistant and hands them to an agent. From that moment the AGENT owns
 * what the assistant may do (the matrix, AssistantMatrixController) and the admin owns whether
 * the assistant exists at all (here). The split is deliberate: the agent knows what work they
 * want done; the admin knows who is allowed on the system.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §12
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantMatrixSnapshotService $snapshots)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('assistants.view'), 403);

        $assignments = AssistantAssignment::with(['assistant', 'assignedAgent', 'branch'])
            ->withTrashed()
            ->latest()
            ->paginate(25);

        return view('admin.assistants.index', [
            'assignments'       => $assignments,
            'assistantsEnabled' => $request->user()->assistantsEnabledForEffectiveAgency(),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasPermission('assistants.create'), 403);

        return view('admin.assistants.create', [
            'agents' => $this->assignableAgents(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('assistants.create'), 403);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'surname'       => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'cell'          => ['required', 'string', 'max:50'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'agent_user_id' => ['required', 'integer', 'exists:users,id'],
            'fica_required' => ['nullable', 'in:0,1'],
        ], [
            'agent_user_id.required' => 'Choose the agent this assistant will work for.',
        ]);

        $agent = $this->validateAgent((int) $data['agent_user_id']);

        $agency = $request->user()->agency;

        $assignment = DB::transaction(function () use ($data, $agent, $request, $agency) {
            $assistant = User::create([
                // `users.name` is the FULL name — there is no surname column. The create form
                // takes them separately (as User Management does) and they are joined here.
                'name'  => trim($data['name'] . ' ' . $data['surname']),
                'email' => strtolower(trim($data['email'])),
                'cell'  => $data['cell'],
                'phone' => $data['phone'] ?? null,

                // The invite flow: no usable password until they set their own via the signed
                // `account.setup` link.
                //
                // NOT the 'INVITE_PENDING' sentinel that UserManagementController uses. That
                // string goes through the `hashed` cast, so what lands in the DB is a valid
                // bcrypt hash OF A PUBLICLY KNOWN CONSTANT — and Auth::attempt() therefore
                // SUCCEEDS for any pending user when the attacker simply types "INVITE_PENDING".
                // (The `verified` middleware stops them reaching most pages, but a session is
                // established as the victim.) Reported separately; not replicated here.
                //
                // A random 64-char secret is unguessable and unusable: the only way in is the
                // signed setup link, which is the whole point of the invite.
                'password'          => Str::random(64),
                'is_active'         => true,
                'email_verified_at' => null,

                // The role MUST be explicit. users.role is NOT NULL DEFAULT 'agent' — omit this
                // and we would create a full agent with a full agent's permissions.
                'role'         => 'assistant',
                'is_assistant' => true,

                'fica_required' => $request->has('fica_required')
                    ? (bool) $data['fica_required']
                    : (bool) ($agency?->assistant_fica_required_default ?? true),

                // An assistant lives in their agent's branch and follows them on transfer.
                'branch_id' => $agent->branch_id,
                'agency_id' => $agent->agency_id,
            ]);

            $assignment = AssistantAssignment::create([
                'agency_id'          => $agent->agency_id,
                'branch_id'          => $agent->branch_id,
                'assistant_user_id'  => $assistant->id,
                'agent_user_id'      => $agent->id,
                'status'             => AssistantAssignment::STATUS_ACTIVE,
                'created_by_user_id' => $request->user()->id,
            ]);

            // D6 — the matrix arrives as a COPY of the agent's permissions, all on, minus the
            // property-upload locked set. The agent trims from there.
            $this->snapshots->snapshot($assignment);

            return $assignment;
        });

        $this->sendInvite($assignment->assistant);
        $this->bustNavCache($agent->id);

        return redirect()
            ->route('admin.assistants.show', $assignment)
            ->with('success', "{$assignment->assistant->name} has been added as an assistant to {$agent->name}. "
                . 'They have been emailed a link to set their password. '
                . "{$agent->name} can now choose exactly what they may do, from My Assistants.");
    }

    public function show(Request $request, AssistantAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.view'), 403);

        $assignment->load(['assistant', 'assignedAgent', 'branch', 'permissions', 'createdBy', 'revokedBy']);

        return view('admin.assistants.show', [
            'assignment'   => $assignment,
            'grantedCount' => $assignment->permissions->where('granted', true)->count(),
            'lockedCount'  => $assignment->permissions->where('is_locked', true)->count(),
            'agents'       => $this->assignableAgents($assignment->assistant_user_id),
        ]);
    }

    /**
     * Move an assistant to a different agent.
     *
     * The old assignment is soft-deleted — it keeps its matrix (the model cascades the
     * soft-delete to the permission rows) and IS the archive. A fresh assignment is created
     * with a fresh copy of the NEW agent's permissions: the old matrix is meaningless against
     * a different ceiling, and starting from the new agent's own set is the same rule as a
     * first assignment.
     */
    public function reassign(Request $request, AssistantAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.reassign'), 403);

        $data = $request->validate([
            'agent_user_id' => ['required', 'integer', 'exists:users,id', 'different:current_agent_id'],
            'reason'        => ['nullable', 'string', 'max:190'],
        ]);

        $newAgent = $this->validateAgent((int) $data['agent_user_id'], $assignment->assistant_user_id);

        if ((int) $newAgent->id === (int) $assignment->agent_user_id) {
            throw ValidationException::withMessages([
                'agent_user_id' => 'That is already their agent.',
            ]);
        }

        $oldAgentId = (int) $assignment->agent_user_id;

        $new = DB::transaction(function () use ($assignment, $newAgent, $request, $data) {
            // Soft-delete first: the generated-column unique index allows only ONE live
            // assignment per assistant, so the old row has to step aside before the new one
            // can exist. That constraint is doing real work here.
            $assignment->forceFill([
                // The archived row must SAY it is closed. The generated column already excludes
                // soft-deleted rows from the unique index, so leaving status='active' here would
                // work — and would leave an "active" assignment sitting in the audit trail for
                // an agent who no longer has this assistant. An audit record that reads wrong is
                // worse than no audit record.
                'status'             => AssistantAssignment::STATUS_REVOKED,
                'revoke_reason'      => $data['reason'] ?? 'Reassigned to another agent',
                'revoked_by_user_id' => $request->user()->id,
                'revoked_at'         => now(),
            ])->save();
            $assignment->delete();

            $new = AssistantAssignment::create([
                'agency_id'          => $newAgent->agency_id,
                'branch_id'          => $newAgent->branch_id,
                'assistant_user_id'  => $assignment->assistant_user_id,
                'agent_user_id'      => $newAgent->id,
                'status'             => AssistantAssignment::STATUS_ACTIVE,
                'created_by_user_id' => $request->user()->id,
            ]);

            // The assistant's branch follows their agent.
            $assignment->assistant()->withTrashed()->first()?->forceFill([
                'branch_id' => $newAgent->branch_id,
            ])->save();

            $this->snapshots->snapshot($new);

            return $new;
        });

        $this->bustNavCache($oldAgentId);
        $this->bustNavCache((int) $newAgent->id);

        return redirect()
            ->route('admin.assistants.show', $new)
            ->with('success', "Reassigned to {$newAgent->name}. Their permissions have been reset to a copy of "
                . "{$newAgent->name}'s — the previous matrix is archived, not deleted.");
    }

    /**
     * Revoke = soft delete. The assignment (and its matrix) is recoverable; the user record is
     * left alone and follows the normal user-deactivation policy. No hard deletes, ever.
     */
    public function revoke(Request $request, AssistantAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.revoke'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $agentId = (int) $assignment->agent_user_id;

        DB::transaction(function () use ($assignment, $request, $data) {
            $assignment->forceFill([
                'status'             => AssistantAssignment::STATUS_REVOKED,
                'revoke_reason'      => $data['reason'] ?? null,
                'revoked_by_user_id' => $request->user()->id,
                'revoked_at'         => now(),
            ])->save();

            $assignment->delete();
        });

        $this->bustNavCache($agentId);

        return redirect()
            ->route('admin.assistants.index')
            ->with('success', 'Assistant access revoked. They can no longer act for the agent. '
                . 'The record is archived and can be restored.');
    }

    public function restore(Request $request, int $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.revoke'), 403);

        $model = AssistantAssignment::withTrashed()->findOrFail($assignment);

        // The unique index permits one live assignment per assistant. If the assistant has
        // been given a new agent in the meantime, restoring this one would be a second live
        // row — the DB would reject it, so say so in plain language instead.
        $liveElsewhere = AssistantAssignment::active()
            ->where('assistant_user_id', $model->assistant_user_id)
            ->exists();

        if ($liveElsewhere) {
            return back()->withErrors([
                'restore' => $model->assistant->name . ' has since been assigned to another agent. '
                    . 'Revoke that assignment first if you want to restore this one.',
            ]);
        }

        DB::transaction(function () use ($model) {
            $model->restore(); // the model's restored() hook brings the matrix back with it
            $model->forceFill([
                'status'             => AssistantAssignment::STATUS_ACTIVE,
                'revoked_at'         => null,
                'revoked_by_user_id' => null,
                'revoke_reason'      => null,
            ])->save();
        });

        $this->bustNavCache((int) $model->agent_user_id);

        return redirect()
            ->route('admin.assistants.show', $model)
            ->with('success', 'Assistant restored, with their previous permissions intact.');
    }

    public function resendInvite(Request $request, AssistantAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('assistants.create'), 403);

        $assistant = $assignment->assistant;

        if ($assistant->email_verified_at) {
            return back()->withErrors([
                'invite' => $assistant->name . ' has already set their password — there is nothing to resend.',
            ]);
        }

        $this->sendInvite($assistant);

        return back()->with('success', "A new setup link has been emailed to {$assistant->email}.");
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * Who may be an Assigned Agent.
     *
     * Excludes, and each exclusion is load-bearing:
     *   - OWNERS (E6). userHasPermission() returns true unconditionally for an owner, so an
     *     owner agent would make the matrix the ONLY limit on their assistant — one mis-ticked
     *     box would hand out super-admin. The intersection needs a real ceiling to intersect
     *     with.
     *   - ASSISTANTS (E5). Chained delegation makes the audit story unprovable ("who actually
     *     authorised this?") and makes the resolver recursive.
     *   - INACTIVE users. An assistant cannot act for someone who cannot act themselves.
     */
    private function assignableAgents(?int $excludeUserId = null)
    {
        $ownerRoles = Role::query()
            ->where('is_owner', true)
            ->pluck('name')
            ->all();

        return User::agencyMembers()
            ->where('is_active', true)
            ->where('is_assistant', false)
            ->when($ownerRoles, fn ($q) => $q->whereNotIn('role', $ownerRoles))
            ->when($excludeUserId, fn ($q) => $q->whereKeyNot($excludeUserId))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'branch_id']);
    }

    private function validateAgent(int $agentId, ?int $excludeUserId = null): User
    {
        $agent = $this->assignableAgents($excludeUserId)->firstWhere('id', $agentId);

        if (!$agent) {
            throw ValidationException::withMessages([
                'agent_user_id' => 'That person cannot be an assistant\'s agent. '
                    . 'Owners, other assistants and deactivated users are not eligible.',
            ]);
        }

        return User::findOrFail($agent->id);
    }

    private function sendInvite(User $assistant): void
    {
        // The EXISTING invite: a 7-day signed `account.setup` URL. Deliberately not a new
        // mailable — an assistant sets their password on exactly the same screen as every other
        // new user in CoreX, and there is one flow to keep working, not two.
        try {
            Mail::to($assistant->email)->send(new UserInviteMail($assistant));
        } catch (\Throwable $e) {
            // A mail failure must not lose the assistant we just created. The admin can resend.
            Log::error('AT-267 assistant invite failed to send', [
                'assistant_user_id' => $assistant->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /** The sidebar's "My Assistants" entry is cached per agent — bust it when it changes. */
    private function bustNavCache(int $agentId): void
    {
        Cache::forget('assistants.agent.' . $agentId);
    }
}
