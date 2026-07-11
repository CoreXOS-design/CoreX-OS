<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DemoAccessGrant;
use App\Models\DemoTncVersion;
use App\Services\Demo\DemoAccessService;
use App\Support\DemoResetSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Demo Access Control — the system-owner admin surface.
 *
 * Spec: .ai/specs/demo-access-control.md §8, §9
 *
 * ══ OWNER-ONLY. NO PERMISSION KEY. ══
 *
 * This is RR Technologies' own sales tooling — the list of companies evaluating
 * CoreX, including agencies who are each other's competitors. It is gated by
 * `owner_only`, and NO keys are added to config/corex-permissions.php.
 *
 * That is deliberate, and it SATISFIES rather than violates non-negotiable #5. A
 * permission key is GRANTABLE: one mis-click in the Role Manager and an agency
 * admin is reading the list of other agencies trialling the product. `owner_only`
 * has no delegation path — isOwnerRole() or 403. It is the stronger gate, not the
 * weaker one. This follows the existing Dev Settings precedent exactly.
 *
 * Enforced at three layers: route middleware, the abort_unless in every action
 * below, and the sidebar's owner-gated block.
 */
class DemoAccessController extends Controller
{
    public function __construct(private readonly DemoAccessService $service)
    {
    }

    /** Layer 2 of 3. The route middleware is layer 1; the sidebar gate is layer 3. */
    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** GET /admin/dev-settings/demo-access */
    public function index(Request $request)
    {
        $this->assertOwner();

        $query = DemoAccessGrant::query()->with(['issuer', 'contact'])->withCount('sessions');

        // Archived grants are hidden by default but NEVER deleted — the toggle is
        // how you prove that to yourself.
        if (! $request->boolean('archived')) {
            $query->notArchived();
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($search) {
                $w->where('company_name', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        $grants = $query->orderByDesc('id')->paginate(25)->withQueryString();

        // Status is DERIVED, so it cannot be filtered in SQL without re-implementing
        // the rules (and drifting from them). Filter the page in PHP against the one
        // authoritative method instead — correctness over a marginally cheaper query.
        $statusFilter = $request->input('status');

        return view('admin.demo-access.index', [
            'grants'       => $grants,
            'statusFilter' => $statusFilter,
            'search'       => $search,
            'showArchived' => $request->boolean('archived'),
            'tncVersion'   => DemoTncVersion::current(),
            'nextReset'    => DemoResetSchedule::next(),
        ]);
    }

    /** GET /admin/dev-settings/demo-access/create */
    public function create()
    {
        $this->assertOwner();

        return view('admin.demo-access.create', [
            'defaultExpiryHours' => DemoAccessService::defaultExpiryHours(),
        ]);
    }

    /** POST /admin/dev-settings/demo-access */
    public function store(Request $request)
    {
        $this->assertOwner();

        $data = $request->validate([
            'company_name'  => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'contact_name'  => ['nullable', 'string', 'max:255'],
            'contact_id'    => ['nullable', 'integer', 'exists:contacts,id'],
            'expiry_hours'  => ['nullable', 'integer', 'min:1', 'max:8760'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ], [
            'company_name.required'  => 'Enter the company name.',
            'contact_email.required' => 'Enter the email address to send the invitation to.',
            'contact_email.email'    => 'Enter a valid email address.',
            'expiry_hours.min'       => 'The demo must last at least 1 hour.',
            'expiry_hours.max'       => 'The demo cannot last longer than a year.',
        ]);

        [$grant, $code] = $this->service->issue($data, Auth::id());

        // The plaintext exists here and nowhere else. Flash it once — after this
        // redirect it is unrecoverable (the DB has only bcrypt(code)), and that is
        // the correct property for a credential.
        return redirect()
            ->route('admin.demo-access.show', $grant)
            ->with('demo_access_code', $code);
    }

    /** GET /admin/dev-settings/demo-access/{grant} */
    public function show(DemoAccessGrant $grant)
    {
        $this->assertOwner();

        $grant->load([
            'issuer',
            'revoker',
            'contact',
            'acceptances.version',
            'sessions' => fn ($q) => $q->orderByDesc('started_at')->limit(50),
            'sessions.pageViews' => fn ($q) => $q->orderByDesc('viewed_at')->limit(200),
        ]);

        return view('admin.demo-access.show', [
            'grant'      => $grant,
            // Flashed exactly once, straight after issue.
            'plainCode'  => session('demo_access_code'),
            'cacheTtl'   => (int) config('corex.instance.gate_cache_ttl', 60),
        ]);
    }

    /** GET /admin/dev-settings/demo-access/{grant}/edit */
    public function edit(DemoAccessGrant $grant)
    {
        $this->assertOwner();

        return view('admin.demo-access.edit', ['grant' => $grant]);
    }

    /**
     * PUT /admin/dev-settings/demo-access/{grant}
     *
     * Notes and the CRM link only.
     *
     * NOT expiry_hours — that length was quoted to the prospect and copied onto
     * the row at issue; editing it later would silently move a deadline they were
     * told. Issue a new grant instead.
     *
     * NOT the access code — the DB holds bcrypt(code) and there is nothing to
     * recover. A "change the code" button would have to mint a new one, which is
     * what issuing a new grant already is.
     */
    public function update(Request $request, DemoAccessGrant $grant)
    {
        $this->assertOwner();

        $data = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_id'   => ['nullable', 'integer', 'exists:contacts,id'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $grant->update([
            'contact_name' => $data['contact_name'] ?? null,
            'contact_id'   => $data['contact_id'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('admin.demo-access.show', $grant)
            ->with('status', 'Grant updated.');
    }

    /** POST /admin/dev-settings/demo-access/{grant}/revoke */
    public function revoke(DemoAccessGrant $grant)
    {
        $this->assertOwner();

        $this->service->revoke($grant, Auth::id());

        $ttl = (int) config('corex.instance.gate_cache_ttl', 60);

        return back()->with('status', "Access revoked. It stops working within {$ttl} seconds.");
    }

    /**
     * DELETE /admin/dev-settings/demo-access/{grant}
     *
     * "Delete" ARCHIVES. The row stays (non-negotiable #1) — a grant is evidence
     * of who accepted which terms and when, and SELECT COUNT(*) on this table must
     * never decrease.
     */
    public function destroy(DemoAccessGrant $grant)
    {
        $this->assertOwner();

        $this->service->archive($grant);

        return redirect()
            ->route('admin.demo-access.index')
            ->with('status', 'Grant archived. It is hidden from the list but kept as a record.');
    }

    /** POST /admin/dev-settings/demo-access/{grant}/restore */
    public function restore(DemoAccessGrant $grant)
    {
        $this->assertOwner();

        $this->service->restore($grant);

        return back()->with('status', 'Grant restored.');
    }

    // ---- T&C versions ------------------------------------------------------

    /** GET /admin/dev-settings/demo-access/tnc */
    public function tnc()
    {
        $this->assertOwner();

        return view('admin.demo-access.tnc', [
            'versions' => DemoTncVersion::with('publisher')
                ->withCount('acceptances')
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    /**
     * POST /admin/dev-settings/demo-access/tnc
     *
     * PUBLISHES A NEW VERSION. There is no update path, by design.
     *
     * Editing published text in place would invalidate every acceptance pointing
     * at it — the acceptance would then attest to text nobody ever saw. Publishing
     * v2 instead re-prompts everyone (including mid-session users) and leaves the
     * v1 acceptances intact and still readable against the v1 body.
     */
    public function publishTnc(Request $request)
    {
        $this->assertOwner();

        $data = $request->validate([
            'body' => ['required', 'string', 'min:20', 'max:50000'],
        ], [
            'body.required' => 'Enter the terms text.',
            'body.min'      => 'The terms look too short to be real. Paste the full text.',
        ]);

        $version = DemoTncVersion::publish($data['body'], Auth::id());

        return redirect()
            ->route('admin.demo-access.tnc')
            ->with('status', "Published version {$version->version}. Everyone will be asked to accept it again — earlier acceptances are kept against the version they signed.");
    }

    // ---- Reset -------------------------------------------------------------

    /**
     * POST /admin/dev-settings/demo-access/reset
     *
     * "Reset now" from primary. NOTE: this runs demo:reset on THIS host, which
     * refuses unless Instance::isDemo() — so from primary it is a no-op that
     * reports the refusal. It exists for an owner logged into the DEMO host.
     * Resetting demo from primary would need primary to reach in and drop demo's
     * database, which is a remote-execution channel this feature deliberately does
     * not build.
     */
    public function reset()
    {
        $this->assertOwner();

        if (! \App\Support\Instance::isDemo()) {
            return back()->withErrors([
                'reset' => 'This is the primary instance — there is no demo database here to reset. Run "Reset now" from the demo host, or wait for the scheduled 3-day reset.',
            ]);
        }

        Artisan::call('demo:reset');

        return back()->with('status', 'Demo database reset. Next scheduled reset: ' . DemoResetSchedule::next()->toDayDateTimeString());
    }
}
