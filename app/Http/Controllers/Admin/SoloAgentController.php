<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformEsign\Document;
use App\Models\SoloAgentSignup;
use App\Models\User;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\SoloAgent\SoloAgentProvisioner;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Solo Agents - the CoreX team's section for single-agent accounts.
 * Spec: .ai/specs/solo-agent-accounts.md sections 4 and 6. OWNER-ONLY at the route (owner_only + permission) and again in every
 * action; the sign-up records are platform-owned, so no agency user has any path to them. The CoreX team sees counts and sign-up
 * facts here, never the agent's contacts or deals.
 */
class SoloAgentController extends Controller
{
    private const SORTS = [
        'agency'   => 'agencies.name',
        'agent'    => 'users.name',
        'email'    => 'users.email',
        'mobile'   => 'users.cell',
        'ffc'      => 'solo_agent_signups.ffc_number',
        'ffc_exp'  => 'solo_agent_signups.ffc_expires_on',
        'type'     => 'agencies.agency_type',
        'created'  => 'solo_agent_signups.created_at',
        'contract' => 'solo_agent_signups.status',
        'status'   => 'status_rank',
        'login'    => 'last_login_at',
        'listings' => 'listings_count',
        'deals'    => 'deals_count',
        'ai'       => 'ai_used',
    ];

    public function __construct(private readonly SoloAgentService $solo, private readonly SoloAgentProvisioner $provisioner)
    {
    }

    private function owner(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole(), 403);

        return $u;
    }

    private function qs(Request $request, string $key, string $default = ''): string
    {
        $v = $request->query($key, $default);

        return is_string($v) ? trim($v) : $default;
    }

    // ── List ───────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->owner($request);
        $list = $this->listing($request);

        return view('admin.solo-agents.index', $list + [
            'fee' => (float) config('solo-agent.monthly_fee_zar', 450),
        ]);
    }

    /**
     * GET /api/v1/solo-agents - the same list, search, sort, filters and paging as the Solo Agents screen, as JSON
     * (non-negotiable #7; spec solo-agent-accounts.md section 10). Owner-only; sign-up facts and counts only - never the
     * agent's contacts or deals. Same query as the screen, so the two can never disagree.
     */
    public function apiIndex(Request $request)
    {
        $this->owner($request);
        $list = $this->listing($request);
        $limit = (int) config('solo-agent.ai_template_monthly_limit', 5);

        $page = $list['rows'];
        $data = $page->getCollection()->map(fn (SoloAgentSignup $r) => [
            'id'         => $r->id,
            'agency'     => ['id' => $r->agency_id, 'name' => $r->agency?->name, 'trading_name' => $r->agency?->trading_name],
            'agent'      => ['id' => $r->user_id, 'name' => $r->user?->name, 'email' => $r->user?->email, 'mobile' => $r->user?->cell],
            'ffc'        => ['number' => $r->ffc_number, 'expires_on' => $r->ffc_expires_on?->toDateString()],
            'does'       => $r->agency?->agency_type ?: 'both',
            'created_at' => $r->created_at?->toIso8601String(),
            'contract'   => ['status' => $r->status, 'sent_at' => $r->contract_sent_at?->toIso8601String(), 'signed_at' => $r->contract_signed_at?->toIso8601String()],
            'status'     => $r->displayStatus(),
            'last_login_at' => $r->last_login_at ? \Illuminate\Support\Carbon::parse($r->last_login_at)->toIso8601String() : null,
            'listings'   => (int) $r->listings_count,
            'deals'      => (int) $r->deals_count,
            'ai_templates' => ['used' => (int) $r->ai_used, 'limit' => $limit],
        ])->all();

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'query' => ['q' => $list['q'], 'status' => $list['status'], 'does' => $list['does'], 'from' => $list['from'], 'to' => $list['to'],
                'ffc_days' => $list['ffcDays'], 'sort' => $list['sort'], 'dir' => $list['dir']],
        ]);
    }

    /** The one listing query (search, filters, sort, paging) behind both the screen and the API. @return array<string,mixed> */
    private function listing(Request $request): array
    {
        $q       = $this->qs($request, 'q');
        $status  = $this->qs($request, 'status');
        $does    = $this->qs($request, 'does');
        $from    = $this->date($this->qs($request, 'from'));
        $to      = $this->date($this->qs($request, 'to'));
        $ffcDays = $this->qs($request, 'ffc_days');
        $sort    = array_key_exists($this->qs($request, 'sort', 'created'), self::SORTS) ? $this->qs($request, 'sort', 'created') : 'created';
        $dir     = $this->qs($request, 'dir', $sort === 'created' ? 'desc' : 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $ai = app(AiTemplateBuilderService::class);

        $query = SoloAgentSignup::withTrashed()
            ->join('agencies', 'agencies.id', '=', 'solo_agent_signups.agency_id')
            ->join('users', 'users.id', '=', 'solo_agent_signups.user_id')
            ->select('solo_agent_signups.*')
            ->selectRaw("CASE WHEN solo_agent_signups.deleted_at IS NOT NULL THEN 4 WHEN agencies.suspended_at IS NOT NULL THEN 3 WHEN solo_agent_signups.status = 'active' THEN 2 ELSE 1 END AS status_rank")
            ->selectSub(DB::table('login_histories')->whereColumn('login_histories.user_id', 'solo_agent_signups.user_id')->where('login_histories.event', 'login')->selectRaw('MAX(created_at)'), 'last_login_at')
            ->selectSub(DB::table('properties')->whereColumn('properties.agency_id', 'agencies.id')->whereNull('properties.deleted_at')->selectRaw('COUNT(*)'), 'listings_count')
            ->selectSub(DB::table('deals')->whereColumn('deals.agency_id', 'agencies.id')->whereNull('deals.deleted_at')->selectRaw('COUNT(*)'), 'deals_count')
            ->selectSub($ai->usedQuery(null)->whereColumn('agency_id', 'agencies.id')->toBase()->selectRaw('COUNT(*)'), 'ai_used')
            ->with(['agency', 'user']);

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('agencies.name', 'like', $like)
                  ->orWhere('users.name', 'like', $like)
                  ->orWhere('users.email', 'like', $like)
                  ->orWhere('users.cell', 'like', $like)
                  ->orWhere('solo_agent_signups.ffc_number', 'like', $like);
            });
        }

        match ($status) {
            'awaiting_contract' => $query->whereNull('solo_agent_signups.deleted_at')->whereNull('agencies.suspended_at')->where('solo_agent_signups.status', '!=', 'active'),
            'active'            => $query->whereNull('solo_agent_signups.deleted_at')->whereNull('agencies.suspended_at')->where('solo_agent_signups.status', 'active'),
            'suspended'         => $query->whereNull('solo_agent_signups.deleted_at')->whereNotNull('agencies.suspended_at'),
            'archived'          => $query->whereNotNull('solo_agent_signups.deleted_at'),
            default             => $query->whereNull('solo_agent_signups.deleted_at'), // archived ones only when asked for
        };

        if (in_array($does, ['sales', 'rentals', 'both'], true)) {
            $query->where('agencies.agency_type', $does);
        }
        if ($from) {
            $query->whereDate('solo_agent_signups.created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('solo_agent_signups.created_at', '<=', $to);
        }
        if (ctype_digit($ffcDays) && (int) $ffcDays > 0) {
            $query->whereNotNull('solo_agent_signups.ffc_expires_on')
                  ->whereDate('solo_agent_signups.ffc_expires_on', '<=', now()->addDays((int) $ffcDays)->toDateString());
        }

        $rows = $query->orderBy(self::SORTS[$sort], $dir)->orderBy('solo_agent_signups.id', 'desc')
            ->paginate($perPage)->withQueryString();

        return [
            'rows' => $rows, 'q' => $q, 'status' => $status, 'does' => $does, 'from' => $from, 'to' => $to,
            'ffcDays' => $ffcDays, 'sort' => $sort, 'dir' => $dir, 'perPage' => $perPage,
        ];
    }

    // ── Create ─────────────────────────────────────────────────────────────

    public function create(Request $request)
    {
        $this->owner($request);

        return view('admin.solo-agents.create', ['fee' => (float) config('solo-agent.monthly_fee_zar', 450)]);
    }

    public function store(Request $request)
    {
        $actor = $this->owner($request);

        $data = $request->validate([
            'agency_name'    => 'required|string|max:100',
            'trading_name'   => 'nullable|string|max:255',
            'name'           => 'required|string|max:191',
            'email'          => 'required|email|max:191|unique:users,email',
            'cell'           => 'required|string|max:50',
            'ffc_number'     => 'required|string|max:50',
            'ffc_expires_on' => 'required|date',
            'agency_type'    => 'nullable|in:sales,rentals,both',
        ], [
            'agency_name.required'    => 'Enter the agency name - it is printed on their documents.',
            'email.unique'            => 'Someone already has a CoreX login with that email address.',
            'ffc_number.required'     => 'Enter the agent\'s Fidelity Fund Certificate number.',
            'ffc_expires_on.required' => 'Enter when the Fidelity Fund Certificate expires.',
        ]);

        $result = $this->provisioner->provision($data, $actor->id);

        return redirect()->route('admin.solo-agents.show', $result['signup']->id)
            ->with('success', 'Single agent signed up. Now send them the agreement - their login opens as soon as they sign it.');
    }

    // ── Read ───────────────────────────────────────────────────────────────

    public function show(Request $request, int $signup)
    {
        $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->with(['agency', 'user'])->findOrFail($signup);

        $ai  = app(AiTemplateBuilderService::class);
        $doc = $signup->agreement_document_id ? Document::withoutGlobalScopes()->find($signup->agreement_document_id) : null;

        return view('admin.solo-agents.show', [
            'signup'    => $signup,
            'doc'       => $doc,
            'events'    => $signup->events()->with('actor')->get(),
            'templates' => $this->solo->availableTemplates(),
            'listings'  => (int) DB::table('properties')->where('agency_id', $signup->agency_id)->whereNull('deleted_at')->count(),
            'deals'     => (int) DB::table('deals')->where('agency_id', $signup->agency_id)->whereNull('deleted_at')->count(),
            'aiUsed'    => $ai->usedThisMonth((int) $signup->agency_id),
            'aiLimit'   => $ai->limitFor((int) $signup->agency_id),
            'quote'     => app(SubscriptionPricingService::class)->quoteFor($signup->agency),
        ]);
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function edit(Request $request, int $signup)
    {
        $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->with(['agency', 'user'])->findOrFail($signup);
        abort_if($signup->trashed(), 404);

        return view('admin.solo-agents.edit', ['signup' => $signup]);
    }

    public function update(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);

        $data = $request->validate([
            'agency_name'    => 'required|string|max:100',
            'trading_name'   => 'nullable|string|max:255',
            'name'           => 'required|string|max:191',
            'cell'           => 'required|string|max:50',
            'ffc_number'     => 'required|string|max:50',
            'ffc_expires_on' => 'required|date',
        ]);

        return $this->act(fn () => $this->solo->updateDetails($signup, $data, $actor->id), $signup, 'Details saved.');
    }

    // ── Actions ────────────────────────────────────────────────────────────

    public function sendContract(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);
        $request->validate(['template_id' => 'nullable|integer']);

        return $this->act(fn () => $this->solo->sendContract($signup, $actor->id, $request->integer('template_id') ?: null), $signup, 'Agreement sent to ' . ($signup->user?->email ?? 'the agent') . '.');
    }

    public function resendSetup(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);

        return $this->act(fn () => $this->solo->resendSetupEmail($signup, $actor->id), $signup, 'Password-setup email sent.');
    }

    public function suspend(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $this->solo->suspend($signup, $actor->id, $data['reason'] ?? null), $signup, 'Account suspended. The agent now sees the standard suspended message.');
    }

    public function unsuspend(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $this->solo->unsuspend($signup, $actor->id, $data['reason'] ?? null), $signup, 'Account reopened.');
    }

    public function archive(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $this->solo->archive($signup, $actor->id, $data['reason'] ?? null), $signup, 'Account archived. Nothing was deleted - you can restore it.', true);
    }

    public function restore(Request $request, int $signup)
    {
        $actor = $this->owner($request);
        $signup = SoloAgentSignup::withTrashed()->findOrFail($signup);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $this->solo->restore($signup, $actor->id, $data['reason'] ?? null), $signup, 'Account restored.');
    }

    /** Run a service action; a refusal comes back as a plain sentence, never an error page. */
    private function act(\Closure $do, SoloAgentSignup $signup, string $ok, bool $toList = false)
    {
        try {
            $do();
        } catch (\DomainException $e) {
            return redirect()->route('admin.solo-agents.show', $signup->id)->with('error', $e->getMessage());
        }

        return $toList
            ? redirect()->route('admin.solo-agents.index')->with('success', $ok)
            : redirect()->route('admin.solo-agents.show', $signup->id)->with('success', $ok);
    }

    private function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
