<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\UserInviteMail;
use App\Models\Role;
use App\Models\User;
use App\Models\Branch;
use App\Services\Admin\AgentDeletionService;
use App\Services\Images\AgentPhotoNormalizer;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();

        $users = User::agencyMembers()
            ->when($agencyId, function ($q) use ($agencyId) {
                $q->where(function ($q2) use ($agencyId) {
                    $q2->where('agency_id', $agencyId)
                        ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
                });
            })
            ->orderBy('name')
            ->get();

        $branches = Branch::when($agencyId, fn ($q) => $q->where(function ($q2) use ($agencyId) {
                $q2->where('agency_id', $agencyId)->orWhereNull('agency_id');
            }))
            ->orderBy('name')
            ->get(['id','name']);
        $designations = DB::table('designations')
            ->where('is_enabled', 1)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name']);

        $p24AgentMap = $this->fetchP24AgentMap($request->boolean('refresh_p24'));

        // PPRA verification due count (never verified or >12 months)
        $ppraDueCount = User::agencyMembers()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->where(function ($q) {
                $q->whereNull('ppra_last_verified_at')
                  ->orWhere('ppra_last_verified_at', '<', now()->subYear());
            })
            ->count();

        return view('admin.users.index', compact('users','branches','designations','p24AgentMap','ppraDueCount'));
    }

    /**
     * Build map of [user_id => P24 agent id] from the P24 agent list.
     * Cached for 10 minutes; pass refresh=true to bust the cache.
     */
    private function fetchP24AgentMap(bool $refresh = false): array
    {
        $cacheKey = 'p24:agent-map:by-source-ref';
        if ($refresh) Cache::forget($cacheKey);

        return Cache::remember($cacheKey, 600, function () {
            try {
                $client = app(Property24ApiClient::class);
                $result = $client->getAgents();
                if (!($result['success'] ?? false)) return [];

                $map = [];
                foreach ($result['data'] ?? [] as $agent) {
                    $ref = $agent['sourceReference'] ?? '';
                    if (preg_match('/^CoreX-Agent-(\d+)$/', $ref, $m)) {
                        $map[(int) $m[1]] = (int) ($agent['id'] ?? 0);
                    }
                }
                return $map;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public function create()
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();

        $branches = Branch::when($agencyId, fn ($q) => $q->where(function ($q2) use ($agencyId) {
                $q2->where('agency_id', $agencyId)->orWhereNull('agency_id');
            }))
            ->orderBy('name')->get(['id','name']);
        $designations = DB::table('designations')
            ->where('is_enabled', 1)->orderBy('sort_order')->orderBy('name')->get(['id','name']);
        $roles = Role::orderBy('sort_order')->get();

        return view('admin.users.create-edit', [
            'user'         => null,
            'branches'     => $branches,
            'designations' => $designations,
            'roles'        => $roles,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'surname'       => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone'         => ['nullable', 'string', 'max:50'],
            'cell'          => ['required', 'string', 'max:50'],
            'fax'           => ['nullable', 'string', 'max:50'],
            'ffc_number'    => ['nullable', 'string', 'max:100'],
            'website'       => ['nullable', 'string', 'max:255'],
            'role'          => ['required', Rule::in(Role::roleNames())],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
            'designation'   => ['nullable', 'string', 'max:100'],
            'agent_cut_percent'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paye_method'                 => ['nullable', 'in:percentage,fixed'],
            'paye_value'                  => ['nullable', 'numeric', 'min:0'],
            'sliding_enabled'             => ['nullable', 'in:0,1'],
            'sliding_tier1_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier2_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier3_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'can_capture_rentals'         => ['nullable', 'in:0,1'],
            'counts_for_branch_split'     => ['nullable', 'in:0,1'],
            'agent_photo'     => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ffc_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'test_agent'      => ['nullable', 'in:0,1'],
            'show_on_website' => ['nullable', 'in:0,1'],
        ]);

        // The owner role cannot be created through user management.
        $submittedRole = Role::allRoles()->firstWhere('name', $data['role']);
        if ($submittedRole && $submittedRole->is_owner) {
            abort(403, 'The owner role cannot be assigned through user management.');
        }

        $isTestAgent = ($request->input('test_agent') === '1');

        $fullName = trim($data['name'] . ' ' . $data['surname']);

        // Permanently remove any previously soft-deleted user with this email
        User::onlyTrashed()->where('email', $data['email'])->forceDelete();

        $user = User::create([
            'name'                        => $fullName,
            'email'                       => $data['email'],
            'password'                    => 'INVITE_PENDING',
            'role'                        => $data['role'],
            'branch_id'                   => $data['branch_id'] ?: null,
            'agency_id'                   => auth()->user()->effectiveAgencyId(),
            'designation'                 => $data['designation'] ?: null,
            'is_active'                   => true,
            'is_admin'                    => in_array($data['role'], ['admin', 'super_admin']) ? 1 : 0,
            'email_verified_at'           => null,
            'agent_cut_percent'           => $data['agent_cut_percent'] ?? 50,
            'paye_method'                 => $data['paye_method'] ?? 'percentage',
            'paye_value'                  => $data['paye_value'] ?? 0,
            'sliding_enabled'             => isset($data['sliding_enabled']) && $data['sliding_enabled'] == '1' ? 1 : 0,
            'sliding_tier1_cut_percent'   => $data['sliding_tier1_cut_percent'] ?? null,
            'sliding_tier2_cut_percent'   => $data['sliding_tier2_cut_percent'] ?? null,
            'sliding_tier3_cut_percent'   => $data['sliding_tier3_cut_percent'] ?? null,
            'can_capture_rentals'         => isset($data['can_capture_rentals']) && $data['can_capture_rentals'] == '1' ? 1 : 0,
            'counts_for_branch_split'     => isset($data['counts_for_branch_split']) && $data['counts_for_branch_split'] == '1' ? 1 : 0,
            'phone'                       => $data['phone'] ?? null,
            'cell'                        => $data['cell'] ?? null,
            'fax'                         => $data['fax'] ?? null,
            'ffc_number'                  => $data['ffc_number'] ?? null,
            'website'                     => $data['website'] ?? null,
            'show_on_website'             => isset($data['show_on_website']) && $data['show_on_website'] == '1' ? 1 : 0,
        ]);

        // Sync branch_assignments
        if ($user->branch_id) {
            DB::table('branch_assignments')->updateOrInsert(
                ['user_id' => $user->id],
                ['branch_id' => (int) $user->branch_id, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // File uploads
        if ($request->hasFile('agent_photo')) {
            $path = app(AgentPhotoNormalizer::class)
                ->store($request->file('agent_photo'), $user->id, $user->agent_photo_path);
            $user->update(['agent_photo_path' => $path]);
        }
        if ($request->hasFile('ffc_certificate')) {
            $ext = $request->file('ffc_certificate')->getClientOriginalExtension();
            $path = $request->file('ffc_certificate')->storeAs("agents/{$user->id}", "ffc.{$ext}", 'public');
            $user->update(['ffc_certificate_path' => $path]);
        }

        if ($isTestAgent) {
            // Test-agent flow: mark verified immediately (bypass invite),
            // force-fill because email_verified_at is not in $fillable.
            $user->forceFill(['email_verified_at' => now()])->save();

            // Register on P24 right away so the agent gets an ID.
            $p24Note = '';
            try {
                $p24 = app(Property24SyndicationService::class);
                $result = $p24->ensureAgentRegisteredByUser($user->fresh());
                if ($result === true) {
                    $agentId = $p24->getP24AgentId($user->fresh());
                    Cache::forget('p24:agent-map:by-source-ref');
                    $p24Note = $agentId ? " P24 agentId: {$agentId}." : ' Registered on P24.';
                } else {
                    $p24Note = ' P24 registration failed: ' . (is_string($result) ? $result : 'unknown');
                }
            } catch (\Throwable $e) {
                $p24Note = ' P24 registration error: ' . $e->getMessage();
            }

            return redirect()->route('admin.users')->with('status', "Test agent \"{$fullName}\" created (no invite email sent).{$p24Note}");
        }

        // Send invitation email
        Mail::to($user->email)->send(new UserInviteMail($user));

        return redirect()->route('admin.users')->with('status', "User \"{$fullName}\" created. An invitation email has been sent to {$user->email}.");
    }

    public function edit(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();

        $branches = Branch::when($agencyId, fn ($q) => $q->where(function ($q2) use ($agencyId) {
                $q2->where('agency_id', $agencyId)->orWhereNull('agency_id');
            }))
            ->orderBy('name')->get(['id','name']);
        $designations = DB::table('designations')
            ->where('is_enabled', 1)->orderBy('sort_order')->orderBy('name')->get(['id','name']);
        $roles = Role::orderBy('sort_order')->get();

        return view('admin.users.create-edit', compact('user', 'branches', 'designations', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'surname'       => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'         => ['nullable', 'string', 'max:50'],
            'cell'          => ['required', 'string', 'max:50'],
            'fax'           => ['nullable', 'string', 'max:50'],
            'ffc_number'    => ['nullable', 'string', 'max:100'],
            'ffc_expiry_date' => ['nullable', 'date'],
            'id_number'     => ['nullable', 'string', 'max:20'],
            'ppra_status'   => ['nullable', 'string', 'in:active,pending,expired,suspended'],
            'website'       => ['nullable', 'string', 'max:255'],
            'role'          => ['required', Rule::in(Role::roleNames())],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
            'designation'   => ['nullable', 'string', 'max:100'],
            'agent_cut_percent'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paye_method'                 => ['nullable', 'in:percentage,fixed'],
            'paye_value'                  => ['nullable', 'numeric', 'min:0'],
            'sliding_enabled'             => ['nullable', 'in:0,1'],
            'sliding_tier1_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier2_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier3_cut_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'can_capture_rentals'         => ['nullable', 'in:0,1'],
            'counts_for_branch_split'     => ['nullable', 'in:0,1'],
            'agent_photo'     => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ffc_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'password'        => ['nullable', 'string', 'min:8'],
            'show_on_website' => ['nullable', 'in:0,1'],
        ]);

        // The owner role cannot be assigned through user management.
        $submittedRole = Role::allRoles()->firstWhere('name', $data['role']);
        if ($submittedRole && $submittedRole->is_owner && !$user->isOwnerRole()) {
            abort(403, 'The owner role cannot be assigned through user management.');
        }
        // And an existing owner cannot be downgraded here either.
        if ($user->isOwnerRole() && (!$submittedRole || !$submittedRole->is_owner)) {
            return back()->withErrors("Cannot change an owner's role.");
        }

        $fullName = trim($data['name'] . ' ' . $data['surname']);

        // Capture originals BEFORE mutation for change-detection (domain events).
        $originalPpraStatus      = $user->getOriginal('ppra_status');
        $originalBranchId        = $user->getOriginal('branch_id');
        $originalCommissionPlan  = [
            'agent_cut_percent'         => $user->getOriginal('agent_cut_percent'),
            'paye_method'               => $user->getOriginal('paye_method'),
            'paye_value'                => $user->getOriginal('paye_value'),
            'sliding_enabled'           => $user->getOriginal('sliding_enabled'),
            'sliding_tier1_cut_percent' => $user->getOriginal('sliding_tier1_cut_percent'),
            'sliding_tier2_cut_percent' => $user->getOriginal('sliding_tier2_cut_percent'),
            'sliding_tier3_cut_percent' => $user->getOriginal('sliding_tier3_cut_percent'),
        ];

        $user->name       = $fullName;
        $user->email      = $data['email'];
        $user->role       = $data['role'];
        $user->is_admin   = in_array($data['role'], ['admin', 'super_admin']) ? 1 : 0;
        $user->branch_id  = $data['branch_id'] ?: null;
        $user->designation = $data['designation'] ?: null;
        $user->show_on_website = isset($data['show_on_website']) && $data['show_on_website'] == '1' ? 1 : 0;

        $user->agent_cut_percent         = $data['agent_cut_percent'] ?? $user->agent_cut_percent;
        $user->paye_method               = $data['paye_method'] ?? $user->paye_method;
        $user->paye_value                = $data['paye_value'] ?? $user->paye_value;
        $user->sliding_enabled           = isset($data['sliding_enabled']) && $data['sliding_enabled'] == '1' ? 1 : 0;
        $user->sliding_tier1_cut_percent = $data['sliding_tier1_cut_percent'] ?? null;
        $user->sliding_tier2_cut_percent = $data['sliding_tier2_cut_percent'] ?? null;
        $user->sliding_tier3_cut_percent = $data['sliding_tier3_cut_percent'] ?? null;
        $user->can_capture_rentals       = isset($data['can_capture_rentals']) && $data['can_capture_rentals'] == '1' ? 1 : 0;
        $user->counts_for_branch_split   = isset($data['counts_for_branch_split']) && $data['counts_for_branch_split'] == '1' ? 1 : 0;

        $user->phone      = $data['phone'] ?? null;
        $user->cell        = $data['cell'] ?? null;
        $user->fax         = $data['fax'] ?? null;
        $user->ffc_number  = $data['ffc_number'] ?? null;
        $user->ffc_expiry_date = $data['ffc_expiry_date'] ?? null;
        $user->id_number   = $data['id_number'] ?? null;
        $user->website     = $data['website'] ?? null;

        // PPRA Status — requires edit_user_ppra_status permission
        if (array_key_exists('ppra_status', $data) && auth()->user()->hasPermission('edit_user_ppra_status')) {
            $oldPpra = $user->getOriginal('ppra_status');
            $user->ppra_status = $data['ppra_status'] ?: null;
            if ($user->ppra_status !== $oldPpra && $user->ppra_status) {
                $user->ppra_last_verified_at = now()->toDateString();
            }
        }

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        // ── Domain events (spec corex-domain-events-spec.md) ─────────────────
        $fresh = $user->fresh() ?? $user;
        if ((string) $originalPpraStatus !== (string) $fresh->ppra_status) {
            event(new \App\Events\Agent\AgentFfcStatusChanged(
                user: $fresh,
                fromStatus: $originalPpraStatus !== null ? (string) $originalPpraStatus : null,
                toStatus: $fresh->ppra_status !== null ? (string) $fresh->ppra_status : null,
                actorUserId: auth()->id(),
            ));
        }
        if ((int) $originalBranchId !== (int) $fresh->branch_id && $fresh->branch_id) {
            $branch = \App\Models\Branch::find($fresh->branch_id);
            if ($branch) {
                event(new \App\Events\Agent\AgentBranchAssigned(
                    user: $fresh,
                    branch: $branch,
                    actorUserId: auth()->id(),
                ));
            }
        }
        $currentCommissionPlan = [
            'agent_cut_percent'         => $fresh->agent_cut_percent,
            'paye_method'               => $fresh->paye_method,
            'paye_value'                => $fresh->paye_value,
            'sliding_enabled'           => $fresh->sliding_enabled,
            'sliding_tier1_cut_percent' => $fresh->sliding_tier1_cut_percent,
            'sliding_tier2_cut_percent' => $fresh->sliding_tier2_cut_percent,
            'sliding_tier3_cut_percent' => $fresh->sliding_tier3_cut_percent,
        ];
        if ($originalCommissionPlan != $currentCommissionPlan) {
            event(new \App\Events\Agent\AgentCommissionPlanChanged(
                user: $fresh,
                plan: $currentCommissionPlan,
                actorUserId: auth()->id(),
            ));
        }

        // Sync FFC expiry date to latest FFC certificate document
        if (isset($data['ffc_expiry_date'])) {
            $latestFfcDoc = $user->documents()
                ->where('document_type', 'ffc_certificate')
                ->latest()->first();
            if ($latestFfcDoc) {
                $latestFfcDoc->update(['expiry_date' => $data['ffc_expiry_date']]);
            }
        }

        // Sync branch_assignments
        if ($user->branch_id) {
            DB::table('branch_assignments')->updateOrInsert(
                ['user_id' => $user->id],
                ['branch_id' => (int) $user->branch_id, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // File uploads
        if ($request->hasFile('agent_photo')) {
            $path = app(AgentPhotoNormalizer::class)
                ->store($request->file('agent_photo'), $user->id, $user->agent_photo_path);
            $user->update(['agent_photo_path' => $path]);
        }
        if ($request->hasFile('ffc_certificate')) {
            if ($user->ffc_certificate_path) {
                Storage::disk('public')->delete($user->ffc_certificate_path);
            }
            $ext = $request->file('ffc_certificate')->getClientOriginalExtension();
            $path = $request->file('ffc_certificate')->storeAs("agents/{$user->id}", "ffc.{$ext}", 'public');
            $user->update(['ffc_certificate_path' => $path]);
        }

        $p24Note = $this->pushUserToP24($user->fresh());

        return redirect()->route('admin.users.edit', $user)->with('status', "User \"{$fullName}\" updated.{$p24Note}");
    }

    /**
     * Push the user's details to Property24.
     * Returns a short status string to append to the flash message.
     * Silent if the user hasn't been synced to P24 yet (no agent ID on file).
     */
    private function pushUserToP24(User $user): string
    {
        try {
            $p24 = app(Property24SyndicationService::class);
            $existingId = $p24->getP24AgentId($user);
            if (!$existingId) {
                // Not on P24 yet — don't auto-register on every edit; require explicit sync.
                return '';
            }

            $result = $p24->updateAgentOnP24($user, pushPhoto: true);
            Cache::forget('p24:agent-map:by-source-ref');

            return $result === true
                ? ' Synced to Property24 (agent #' . $existingId . ').'
                : ' P24 sync warning: ' . (is_string($result) ? $result : 'unknown');
        } catch (\Throwable $e) {
            return ' P24 sync error: ' . $e->getMessage();
        }
    }

    public function updateDefaults(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $data = $request->validate([
            'agent_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paye_method' => ['nullable', 'in:percentage,fixed'],
            'paye_value' => ['nullable', 'numeric', 'min:0'],

            // Sliding scale (per agent, optional)
            'sliding_enabled' => ['nullable', 'in:0,1'],
            'sliding_tier1_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier2_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier3_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // Normalize blanks
        $agentCut = $data['agent_cut_percent'] ?? null;
        $payeMethod = $data['paye_method'] ?? null;
        $payeValue = $data['paye_value'] ?? null;

        if ($payeMethod === null) {
            // If method blank, also blank value (keeps data consistent)
            $payeValue = null;
        }

        $slidingEnabled = isset($data['sliding_enabled']) && (string)$data['sliding_enabled'] === '1';

        $tier1 = $data['sliding_tier1_cut_percent'] ?? null;
        $tier2 = $data['sliding_tier2_cut_percent'] ?? null;
        $tier3 = $data['sliding_tier3_cut_percent'] ?? null;

        // If sliding enabled, tiers must be provided (no placeholders; concrete rule)
        if ($slidingEnabled) {
            if ($tier1 === null || $tier1 === '' || $tier2 === null || $tier2 === '' || $tier3 === null || $tier3 === '') {
                return back()->withErrors("Sliding is enabled for {$user->name}. Tier 1, Tier 2, and Tier 3 cut % are required.")
                    ->withInput();
            }
        } else {
            // If sliding disabled, keep tiers nullable (do not force blanks to 0)
            if ($tier1 === '') $tier1 = null;
            if ($tier2 === '') $tier2 = null;
            if ($tier3 === '') $tier3 = null;
        }

        $beforePlan = [
            'agent_cut_percent'         => $user->agent_cut_percent,
            'paye_method'               => $user->paye_method,
            'paye_value'                => $user->paye_value,
            'sliding_enabled'           => $user->sliding_enabled,
            'sliding_tier1_cut_percent' => $user->sliding_tier1_cut_percent,
            'sliding_tier2_cut_percent' => $user->sliding_tier2_cut_percent,
            'sliding_tier3_cut_percent' => $user->sliding_tier3_cut_percent,
        ];

        $user->update([
            'agent_cut_percent' => ($agentCut === null || $agentCut === '') ? null : (float)$agentCut,
            'paye_method' => $payeMethod,
            'paye_value' => ($payeValue === null || $payeValue === '') ? null : (float)$payeValue,

            'sliding_enabled' => $slidingEnabled ? 1 : 0,
            'sliding_tier1_cut_percent' => ($tier1 === null || $tier1 === '') ? null : (float)$tier1,
            'sliding_tier2_cut_percent' => ($tier2 === null || $tier2 === '') ? null : (float)$tier2,
            'sliding_tier3_cut_percent' => ($tier3 === null || $tier3 === '') ? null : (float)$tier3,
        ]);

        // Domain event — AgentCommissionPlanChanged on actual change.
        $fresh = $user->fresh() ?? $user;
        $afterPlan = [
            'agent_cut_percent'         => $fresh->agent_cut_percent,
            'paye_method'               => $fresh->paye_method,
            'paye_value'                => $fresh->paye_value,
            'sliding_enabled'           => $fresh->sliding_enabled,
            'sliding_tier1_cut_percent' => $fresh->sliding_tier1_cut_percent,
            'sliding_tier2_cut_percent' => $fresh->sliding_tier2_cut_percent,
            'sliding_tier3_cut_percent' => $fresh->sliding_tier3_cut_percent,
        ];
        if ($beforePlan != $afterPlan) {
            event(new \App\Events\Agent\AgentCommissionPlanChanged(
                user: $fresh,
                plan: $afterPlan,
                actorUserId: auth()->id(),
            ));
        }

        return back()->with('status', "Defaults updated for {$user->name}.");
    }

    
    public function updateRole(Request $request, User $user)
    {
        // ---- ALSO SAVE DEFAULTS FROM SAME FORM ----
        $defaults = $request->validate([
            'agent_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'paye_method' => ['nullable','in:percentage,fixed'],
            'paye_value' => ['nullable','numeric','min:0'],
            'sliding_enabled' => ['nullable','in:0,1'],
            'sliding_tier1_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'sliding_tier2_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'sliding_tier3_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'can_capture_rentals' => ['nullable','in:0,1'],
              'counts_for_branch_split' => ['nullable','in:0,1'],
        ]);

        // Capture originals BEFORE mutation for change-detection (domain events).
        $originalBranchIdUR        = $user->getOriginal('branch_id');
        $originalCommissionPlanUR  = [
            'agent_cut_percent'         => $user->getOriginal('agent_cut_percent'),
            'paye_method'               => $user->getOriginal('paye_method'),
            'paye_value'                => $user->getOriginal('paye_value'),
            'sliding_enabled'           => $user->getOriginal('sliding_enabled'),
            'sliding_tier1_cut_percent' => $user->getOriginal('sliding_tier1_cut_percent'),
            'sliding_tier2_cut_percent' => $user->getOriginal('sliding_tier2_cut_percent'),
            'sliding_tier3_cut_percent' => $user->getOriginal('sliding_tier3_cut_percent'),
        ];

        $user->agent_cut_percent = $defaults['agent_cut_percent'] ?? $user->agent_cut_percent;
        $user->paye_method = $defaults['paye_method'] ?? $user->paye_method;
        $user->paye_value = $defaults['paye_value'] ?? $user->paye_value;
        $user->sliding_enabled = isset($defaults['sliding_enabled']) && $defaults['sliding_enabled'] == '1' ? 1 : 0;
        $user->sliding_tier1_cut_percent = $defaults['sliding_tier1_cut_percent'] ?? $user->sliding_tier1_cut_percent;
        $user->sliding_tier2_cut_percent = $defaults['sliding_tier2_cut_percent'] ?? $user->sliding_tier2_cut_percent;
        $user->sliding_tier3_cut_percent = $defaults['sliding_tier3_cut_percent'] ?? $user->sliding_tier3_cut_percent;
        $user->can_capture_rentals = isset($defaults['can_capture_rentals']) && $defaults['can_capture_rentals'] == '1' ? 1 : 0;

          $user->counts_for_branch_split = isset($defaults['counts_for_branch_split']) && $defaults['counts_for_branch_split'] == '1' ? 1 : 0;

        // ---- Contact fields ----
        $contact = $request->validate([
            'phone' => ['nullable','string','max:50'],
            'cell' => ['required','string','max:50'],
            'fax' => ['nullable','string','max:50'],
            'ffc_number' => ['nullable','string','max:100'],
            'website' => ['nullable','string','max:255'],
        ]);
        $user->phone = $contact['phone'] ?? null;
        $user->cell = $contact['cell'] ?? null;
        $user->fax = $contact['fax'] ?? null;
        $user->ffc_number = $contact['ffc_number'] ?? null;
        $user->website = $contact['website'] ?? null;

        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        // Safety: prevent editing your own role/branch by mistake
        if ($user->id === auth()->id()) {
            return back()->withErrors('For safety, you cannot change your own role/branch here.');
        }

        // Guard 1: Non-owners cannot modify owner accounts at all
        if ($user->isOwnerRole() && !auth()->user()->isOwnerRole()) {
            abort(403, 'You do not have permission to modify an owner account.');
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(Role::roleNames())],
            'designation' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $role = (string)$data['role'];

        // Guard 2: Cannot downgrade an owner to a non-owner role
        $submittedRole = Role::allRoles()->firstWhere('name', $role);
        if ($user->isOwnerRole() && (!$submittedRole || !$submittedRole->is_owner)) {
            return back()->withErrors("Cannot change an owner's role.");
        }

        // Guard 3: Owner role cannot be assigned via the UI under any circumstance.
        if ($submittedRole && $submittedRole->is_owner && !$user->isOwnerRole()) {
            abort(403, 'The owner role cannot be assigned through user management.');
        }
        $branchId = $data['branch_id'] ?? null;

        if ($branchId !== null && (int)$branchId <= 0) {
            $branchId = null;
        }

        // If BM/Agent, a branch is required (predictable behavior)
        if ($role !== 'admin' && $branchId === null) {
            return back()->withErrors('Branch is required for Agent and Branch Manager.');
        }

        // Ensure branch exists if provided
        if ($branchId !== null) {
            $exists = \Illuminate\Support\Facades\DB::table('branches')->where('id', (int)$branchId)->exists();
            if (!$exists) return back()->withErrors('Selected branch does not exist.');
        }

        $user->role = $role;
        $user->is_admin = in_array($role, ['admin', 'super_admin']) ? 1 : 0;
        $user->branch_id = $branchId;

        // Designation (blank => NULL)
        $designation = trim((string)($data['designation'] ?? ''));
        $user->designation = ($designation !== '') ? $designation : null;

        $user->is_active = 1;
        if (!$user->email_verified_at) $user->email_verified_at = now();
        $user->save();

        // ── Domain events (spec corex-domain-events-spec.md) ─────────────────
        $freshUR = $user->fresh() ?? $user;
        if ((int) $originalBranchIdUR !== (int) $freshUR->branch_id && $freshUR->branch_id) {
            $branch = \App\Models\Branch::find($freshUR->branch_id);
            if ($branch) {
                event(new \App\Events\Agent\AgentBranchAssigned(
                    user: $freshUR,
                    branch: $branch,
                    actorUserId: auth()->id(),
                ));
            }
        }
        $currentCommissionPlanUR = [
            'agent_cut_percent'         => $freshUR->agent_cut_percent,
            'paye_method'               => $freshUR->paye_method,
            'paye_value'                => $freshUR->paye_value,
            'sliding_enabled'           => $freshUR->sliding_enabled,
            'sliding_tier1_cut_percent' => $freshUR->sliding_tier1_cut_percent,
            'sliding_tier2_cut_percent' => $freshUR->sliding_tier2_cut_percent,
            'sliding_tier3_cut_percent' => $freshUR->sliding_tier3_cut_percent,
        ];
        if ($originalCommissionPlanUR != $currentCommissionPlanUR) {
            event(new \App\Events\Agent\AgentCommissionPlanChanged(
                user: $freshUR,
                plan: $currentCommissionPlanUR,
                actorUserId: auth()->id(),
            ));
        }

        // Keep branch_assignments in sync for any older logic that relies on it
        if ($branchId !== null) {
            \Illuminate\Support\Facades\DB::table('branch_assignments')
                ->updateOrInsert(
                    ['user_id' => $user->id],
                    ['branch_id' => (int)$branchId, 'updated_at' => now(), 'created_at' => now()]
                );
        }

        // ---- Agent file uploads ----
        $request->validate([
            'agent_photo'     => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ffc_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('agent_photo')) {
            $path = app(AgentPhotoNormalizer::class)
                ->store($request->file('agent_photo'), $user->id, $user->agent_photo_path);
            $user->update(['agent_photo_path' => $path]);
        }

        if ($request->hasFile('ffc_certificate')) {
            if ($user->ffc_certificate_path) {
                Storage::disk('public')->delete($user->ffc_certificate_path);
            }
            $ext = $request->file('ffc_certificate')->getClientOriginalExtension();
            $path = $request->file('ffc_certificate')->storeAs(
                "agents/{$user->id}", "ffc.{$ext}", 'public'
            );
            $user->update(['ffc_certificate_path' => $path]);
        }

        $p24Note = $this->pushUserToP24($user->fresh());

        return back()->with('status', "Updated role/branch for {$user->name}.{$p24Note}");
    }

    public function resendInvite(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->email_verified_at) {
            return back()->withErrors('This user has already set up their account.');
        }

        Mail::to($user->email)->send(new UserInviteMail($user));

        return back()->with('status', "Invitation email resent to {$user->email}.");
    }

    /**
     * Remove an agent file (photo or FFC certificate).
     */
    public function removeAgentFile(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $field = $request->input('field');

        if ($field === 'agent_photo' && $user->agent_photo_path) {
            Storage::disk('public')->delete($user->agent_photo_path);
            $user->update(['agent_photo_path' => null]);
            return back()->with('status', "Agent photo removed for {$user->name}.");
        }

        if ($field === 'ffc_certificate' && $user->ffc_certificate_path) {
            Storage::disk('public')->delete($user->ffc_certificate_path);
            $user->update(['ffc_certificate_path' => null]);
            return back()->with('status', "FFC certificate removed for {$user->name}.");
        }

        return back();
    }

    /**
     * Push a user to Property24 so they get a P24 agent ID.
     * Safe to call repeatedly — if the agent already exists on P24
     * (matched via sourceReference), no duplicate is created.
     */
    public function syncP24(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        try {
            $p24 = app(Property24SyndicationService::class);
            $result = $p24->ensureAgentRegisteredByUser($user);

            if ($result !== true) {
                return back()->withErrors('P24 sync failed: ' . (is_string($result) ? $result : 'unknown error'));
            }

            $agentId = $p24->getP24AgentId($user);
            Cache::forget('p24:agent-map:by-source-ref');

            return back()->with('status', $agentId
                ? "Synced {$user->name} to Property24. Agent ID: {$agentId}."
                : "Synced {$user->name} to Property24 (agent ID unavailable).");
        } catch (\Throwable $e) {
            return back()->withErrors('P24 sync error: ' . $e->getMessage());
        }
    }

    public function toggle(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->id === auth()->id()) {
            return back()->withErrors('You cannot deactivate yourself.');
        }

        $user->update([
            'is_active' => !$user->is_active
        ]);

        $fresh = $user->fresh();
        $p24Note = $this->pushUserToP24($fresh);
        $state = $fresh->is_active ? 'activated' : 'deactivated';

        // Domain events — spec .ai/specs/corex-domain-events-spec.md
        if ($fresh->is_active) {
            event(new \App\Events\Agent\AgentActivated($fresh, auth()->id()));
        } else {
            event(new \App\Events\Agent\AgentDeactivated($fresh, auth()->id()));
        }

        return back()->with('status', "{$user->name} {$state}.{$p24Note}");
    }

    /**
     * Quick on/off toggle for an agent's website visibility (show_on_website).
     * Saving fires the UserObserver → agent.published / agent.removed webhook.
     * Spec: .ai/specs/agency-public-api.md §2 (layer 3), §6.1.
     */
    public function toggleWebsite(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $user->update(['show_on_website' => ! $user->show_on_website]);

        return response()->json([
            'success'         => true,
            'show_on_website' => (bool) $user->show_on_website,
        ]);
    }

    /**
     * JSON preview for the agent-delete modal: counts of attached records
     * and the list of eligible reassignment targets in the same agency.
     */
    public function deletePreview(User $user, AgentDeletionService $service)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->id === auth()->id()) {
            return response()->json([
                'error' => 'You cannot delete yourself.',
            ], 422);
        }

        $counts = $service->preview($user);

        $targets = User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where('agency_id', $user->agency_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'user' => [
                'id'   => $user->id,
                'name' => $user->name,
            ],
            'qr' => [
                'slug' => $user->ensureQrSlug(),
            ],
            'counts'  => $counts,
            'targets' => $targets->map(fn ($u) => [
                'id'    => $u->id,
                'label' => trim($u->name ?? '').($u->email ? " ({$u->email})" : ''),
            ])->values(),
        ]);
    }

    public function delete(Request $request, User $user, AgentDeletionService $service)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->id === auth()->id()) {
            return back()->withErrors('You cannot delete yourself.');
        }

        $name = $user->name;

        // QR rerouting is mandatory on every agent delete — every agent has a
        // QR slug and printed codes must never dead-end. Spec: agent-qr-onboarding.md
        $sameAgencyActive = function ($q) use ($user) {
            $q->where('agency_id', $user->agency_id)->where('is_active', true)->whereNull('deleted_at');
        };

        $qrData = $request->validate([
            'qr_reroute_user_id' => ['required', 'integer', 'different:user', Rule::exists('users', 'id')->where($sameAgencyActive)],
        ], [
            'qr_reroute_user_id.required' => 'Choose an agent to reroute this agent\'s QR code to.',
            'qr_reroute_user_id.exists'   => 'The chosen QR reroute agent is not a valid active user in this agency.',
        ]);

        $qrTarget = User::findOrFail($qrData['qr_reroute_user_id']);
        $service->setQrReroute($user, $qrTarget, (int) auth()->id());

        // Decide whether reassignment is needed.
        $counts = $service->preview($user);

        if ($counts['has_any']) {
            $data = $request->validate([
                'target_user_id'      => ['required', 'integer', 'different:user', Rule::exists('users', 'id')->where(function ($q) use ($user) {
                    $q->where('agency_id', $user->agency_id)->where('is_active', true)->whereNull('deleted_at');
                })],
                'secondary_handling'  => ['required', Rule::in(['promote', 'replace'])],
            ], [
                'target_user_id.required' => 'Choose an agent to reassign records to.',
                'target_user_id.exists'   => 'The chosen agent is not a valid active user in this agency.',
            ]);

            $target = User::findOrFail($data['target_user_id']);
            $service->reassignAndCleanup($user, $target, $data['secondary_handling'], (int) auth()->id());
        }

        DB::table('branch_assignments')->where('user_id', $user->id)->delete();

        $user->update(['is_active' => false]);

        // Mark inactive on P24 BEFORE soft-deleting so we still have the user to push.
        $p24Note = $this->pushUserToP24($user->fresh());

        $user->delete(); // soft delete (sets deleted_at)

        return redirect()->route('admin.users')->with('status', "User \"{$name}\" has been deleted.{$p24Note}");
    }
}
