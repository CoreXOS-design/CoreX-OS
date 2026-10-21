<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\RentalNoticeTemplate;
use App\Services\Rentals\LeaseBreachService;
use App\Services\Rentals\RentalNoticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * .ai/specs/rental-notices.md - breach notices and notices to vacate: the register, the bulk payment-breach screen, the one-off breach, remedy,
 * vacate. A breach is exactly as visible as its lease (own / branch / agency); direct-URL access by id is blocked.
 */
class LeaseBreachController extends Controller
{
    private const SORTS = ['issued_on', 'remedy_deadline', 'amount', 'status', 'property'];
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /** The register's status tiles (rental-notices.md §7b): the breach's real states, with "issued" split by whether its remedy-by date is reached. */
    private const TILES = [
        'open' => 'Open',
        'reached' => 'Remedy date reached',
        'remedied' => 'Remedied',
        'vacate_sent' => 'Notice to vacate sent',
    ];

    public function __construct(private readonly LeaseBreachService $breaches, private readonly RentalNoticeService $notices)
    {
    }

    // ── register ────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $query = LeaseBreach::query()->with(['lease.property', 'lease.tenants.contact', 'issuedBy'])
            ->whereIn('lease_id', Lease::query()->withTrashed()->visibleTo($request->user())->select('leases.id'));

        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }
        $status = (string) $request->get('status', '');
        if (in_array($status, LeaseBreach::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($request->get('kind'), [LeaseBreach::KIND_PAYMENT, LeaseBreach::KIND_OTHER], true)) {
            $query->where('kind', $request->get('kind'));
        }
        if (in_array($request->get('party'), [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            $query->where('breach_party', $request->get('party'));
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('issued_on', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('issued_on', '<=', $to);
        }
        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('clause', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('lease.property', fn ($p) => $p->withTrashed()->where(fn ($pp) => $pp->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereIn('lease_id', fn ($l) => $l->select('leases.id')->from('leases')
                        ->join('contact_property', 'contact_property.property_id', '=', 'leases.property_id')->join('contacts', 'contacts.id', '=', 'contact_property.contact_id')
                        ->where('contact_property.role', 'landlord')->where(fn ($c) => $c->where('contacts.first_name', 'like', "%{$search}%")->orWhere('contacts.last_name', 'like', "%{$search}%")));
            });
        }

        // rental-notices.md §7b - status tiles. Each count is the SAME query the list runs (same own / branch / agency scope, search and filters)
        // plus that tile's own condition, so a tile's number is exactly the rows it shows when clicked. Clicking the active tile clears it.
        $counts = ['total' => (clone $query)->count()];
        foreach (array_keys(self::TILES) as $key) {
            $counts[$key] = $this->applyTile(clone $query, $key)->count();
        }
        $tile = (string) $request->get('tile', '');
        $tile = isset(self::TILES[$tile]) ? $tile : '';
        if ($tile !== '') {
            $this->applyTile($query, $tile);
        }

        $sort = in_array($request->get('sort'), self::SORTS, true) ? $request->get('sort') : 'issued_on';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'property') {
            $query->orderByRaw('(select p.address from properties p where p.id = (select l.property_id from leases l where l.id = lease_breaches.lease_id)) ' . $dir);
        } else {
            $query->orderBy($sort, $dir);
        }
        $query->orderByDesc('id');

        // the shared rental list controls offer the same per-page choices as the other rentals lists
        $perPage = (int) $request->get('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        return view('corex.breaches.index', [
            'breaches' => $query->paginate($perPage)->withQueryString(), 'sort' => $sort, 'dir' => $dir,
            'tiles' => self::TILES, 'tileCounts' => $counts, 'tile' => $tile,
            'perPage' => $perPage, 'perPageOptions' => self::PER_PAGE_OPTIONS, 'showArchived' => $request->boolean('archived'),
        ]);
    }

    /** One tile's own condition, on top of whatever the list already filters by. */
    private function applyTile($query, string $tile)
    {
        $today = \Carbon\Carbon::today()->toDateString();

        return match ($tile) {
            'open' => $query->where('status', LeaseBreach::STATUS_ISSUED)->where(fn ($w) => $w->whereNull('remedy_deadline')->orWhereDate('remedy_deadline', '>', $today)),
            'reached' => $query->where('status', LeaseBreach::STATUS_ISSUED)->whereDate('remedy_deadline', '<=', $today),
            'remedied' => $query->where('status', LeaseBreach::STATUS_REMEDIED),
            'vacate_sent' => $query->where('status', LeaseBreach::STATUS_ESCALATED),
            default => $query,
        };
    }

    public function show(Request $request, int $id): View
    {
        $breach = $this->find($request, $id);
        $lease = $breach->lease;
        $lease->loadMissing(['property', 'tenants.contact']);
        $vacateTemplates = $this->breaches->templatesFor((int) $breach->agency_id, 'vacate');

        return view('corex.breaches.show', [
            'breach' => $breach, 'lease' => $lease, 'vacateTemplates' => $vacateTemplates, 'canVacate' => $breach->canIssueVacate(),
            'placeholders' => RentalNoticeService::PLACEHOLDERS, 'preview' => session('breach_preview'),
            // the notice to vacate form starts from the agency's usual time and the breach notices on file (the agent can change both)
            'vacateTimeDefault' => \App\Models\LeaseSetting::vacateTimeFor((int) $breach->agency_id),
            'vacateDateDefault' => $this->breaches->defaultVacateDate((int) $breach->agency_id)->toDateString(),
            'vacateNoticeDays' => \App\Models\LeaseSetting::vacateNoticeDaysFor((int) $breach->agency_id),
            'breachSummaryDefault' => $this->notices->breachSummary($lease),
        ]);
    }

    // ── bulk payment breach ─────────────────────────────────────────────────

    public function bulk(Request $request): View|RedirectResponse
    {
        $ids = collect((array) $request->input('leases', []))->map(fn ($i) => (int) $i)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return redirect()->route('corex.leases.index')->with('error', 'Tick at least one lease first.');
        }
        $leases = $this->visibleActiveLeases($request, $ids->all());
        $dropped = $ids->count() - $leases->count();

        return view('corex.breaches.bulk', [
            'leases' => $leases, 'dropped' => $dropped, 'templates' => $this->breaches->templatesFor((int) $request->user()->effectiveAgencyId(), 'payment'),
            'remedyDays' => $this->breaches->remedyDays((int) $request->user()->effectiveAgencyId()),
            'remedyWindow' => $this->breaches->remedyWindowText((int) $request->user()->effectiveAgencyId()),
            'placeholders' => RentalNoticeService::PLACEHOLDERS,
        ]);
    }

    public function bulkStore(Request $request): RedirectResponse|View
    {
        $data = $request->validate([
            'rental_notice_template_id' => ['required', 'integer'],
            'amount' => ['required', 'array'],
            'amount.*' => ['nullable', 'string', 'max:30'],
            'figures_raw' => ['nullable', 'string', 'max:5000'],
        ], ['rental_notice_template_id.required' => 'Choose the breach notice template.']);

        $ids = collect(array_keys($data['amount']))->map(fn ($i) => (int) $i)->all();
        $leases = $this->visibleActiveLeases($request, $ids);
        $template = $this->breaches->templatesFor((int) $request->user()->effectiveAgencyId(), 'payment')->firstWhere('id', (int) $data['rental_notice_template_id']);
        if (! $template) {
            return back()->withInput()->withErrors(['rental_notice_template_id' => 'Choose one of your agency\'s payment breach templates.']);
        }
        if ($leases->isEmpty()) {
            return back()->withInput()->withErrors(['amount' => 'None of those leases can take a breach notice.']);
        }

        $withAmount = $leases->filter(fn ($l) => $this->breaches->parseAmount($data['amount'][$l->id] ?? null) !== null);
        if ($withAmount->isEmpty()) {
            return back()->withInput()->withErrors(['amount' => 'Fill in the outstanding amount on at least one lease - only leases with an amount get a breach notice.']);
        }

        try {
            $result = $this->breaches->issueBulkPayment($leases, $data['amount'], $template, $request->user(), $this->parseFigures((string) ($data['figures_raw'] ?? '')));
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return view('corex.breaches.bulk-result', ['result' => $result, 'ignored' => $leases->count() - $withAmount->count()]);
    }

    // ── one-off "other" breach ──────────────────────────────────────────────

    public function create(Request $request, Lease $lease): View
    {
        $this->authoriseLease($lease, $request);

        $agencyId = (int) $lease->agency_id;
        $templates = $this->breaches->templatesFor($agencyId, 'breach');

        return view('corex.breaches.create', [
            'lease' => $lease, 'templates' => $templates,
            'remedyDays' => $this->breaches->remedyDays($agencyId),
            'remedyWindow' => $this->breaches->remedyWindowText($agencyId),
            // each template's own remedy period, so the screen can say how long the chosen one allows
            'windowByTemplate' => $templates->mapWithKeys(fn ($t) => [$t->id => $this->breaches->remedyWindowText($agencyId, $this->breaches->remedyDaysFor($t, $agencyId))])->all(),
            'placeholders' => RentalNoticeService::PLACEHOLDERS, 'preview' => session('breach_preview'),
            // AT-495 - "Use this template" on the Templates tab arrives with the template already chosen
            'chosenTemplateId' => (int) $request->query('template'),
        ]);
    }

    public function store(Request $request, Lease $lease): RedirectResponse
    {
        $this->authoriseLease($lease, $request);
        $data = $request->validate([
            'rental_notice_template_id' => ['required', 'integer'],
            'breach_party' => ['nullable', 'in:' . Lease::NOTICE_BY_TENANT . ',' . Lease::NOTICE_BY_LANDLORD],
            'amount' => ['nullable', 'string', 'max:30'],
            'clause' => ['nullable', 'string', 'max:3000'],
            'reason' => ['nullable', 'string', 'max:3000'],
            'figures_raw' => ['nullable', 'string', 'max:5000'],
            'intent' => ['required', 'in:preview,send'],
        ], ['rental_notice_template_id.required' => 'Choose the breach notice template.']);

        $template = $this->breaches->templatesFor((int) $lease->agency_id, 'breach')->firstWhere('id', (int) $data['rental_notice_template_id']);
        if (! $template) {
            return back()->withInput()->withErrors(['rental_notice_template_id' => 'Choose one of your agency\'s breach templates.']);
        }
        $figures = $this->parseFigures((string) ($data['figures_raw'] ?? ''));
        $isPayment = $template->notice_type === RentalNoticeTemplate::TYPE_BREACH_PAYMENT;
        $clause = trim((string) ($data['clause'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));

        // a payment template asks for the amount outstanding (always the tenant); an ad hoc one for who, the clause and the reason
        if ($isPayment) {
            $amount = $this->breaches->parseAmount($data['amount'] ?? null);
            if ($amount === null || $amount <= 0) {
                return back()->withInput()->withErrors(['amount' => 'Enter the amount outstanding (more than R 0).']);
            }
            $party = Lease::NOTICE_BY_TENANT;
        } else {
            $amount = null;
            $party = (string) ($data['breach_party'] ?? '');
            $errors = [];
            if (! in_array($party, [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
                $errors['breach_party'] = 'Choose who is in breach.';
            }
            if ($clause === '') {
                $errors['clause'] = 'Quote the lease clause that was breached.';
            }
            if ($reason === '') {
                $errors['reason'] = 'Say what the breach is.';
            }
            if ($errors) {
                return back()->withInput()->withErrors($errors);
            }
        }

        if ($data['intent'] === 'preview') {
            $preview = $this->breaches->previewBreach($lease, $isPayment ? LeaseBreach::KIND_PAYMENT : LeaseBreach::KIND_OTHER, $party, $amount, $clause, $reason, $template, $request->user(), $figures);

            return back()->withInput()->with('breach_preview', $preview + ['template' => $template->name]);
        }

        try {
            $breach = $isPayment
                ? $this->breaches->issuePayment($lease, (float) $amount, $template, $request->user(), null, $figures, $clause)
                : $this->breaches->issueOther($lease, $party, $clause, $reason, $template, $request->user(), $figures);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.breaches.show', $breach->id)->with('success', 'Breach notice sent. The other party has been told.');
    }

    // ── remedy / vacate / archive ───────────────────────────────────────────

    public function remedy(Request $request, int $id): RedirectResponse
    {
        $breach = $this->find($request, $id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'remedied_on' => ['nullable', 'date'], 'return' => ['nullable', 'in:lease']]);
        try {
            $this->breaches->remedy($breach, $request->user(), $data['note'] ?? null, $data['remedied_on'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        // from the lease screen's Next bar the agent goes back to the lease
        if (($data['return'] ?? null) === 'lease') {
            return redirect()->route('corex.leases.show', $breach->lease_id)->with('success', 'Breach marked as remedied.');
        }

        return redirect()->route('corex.breaches.show', $breach->id)->with('success', 'Marked as remedied.');
    }

    public function vacate(Request $request, int $id): RedirectResponse
    {
        $breach = $this->find($request, $id);
        $data = $request->validate([
            'rental_notice_template_id' => ['required', 'integer'],
            'vacate_date' => ['nullable', 'date'],
            'vacate_time' => ['nullable', 'date_format:H:i'],
            'breach_summary' => ['nullable', 'string', 'max:3000'],
            'figures_raw' => ['nullable', 'string', 'max:5000'],
            'intent' => ['required', 'in:preview,send'],
        ], ['rental_notice_template_id.required' => 'Choose the notice to vacate template.', 'vacate_date.date' => 'That is not a date.', 'vacate_time.date_format' => 'Give the time as hours and minutes, e.g. 12:00.']);

        $template = $this->breaches->templatesFor((int) $breach->agency_id, 'vacate')->firstWhere('id', (int) $data['rental_notice_template_id']);
        if (! $template) {
            return back()->withErrors(['rental_notice_template_id' => 'Choose one of your agency\'s notice to vacate templates.']);
        }
        $figures = $this->parseFigures((string) ($data['figures_raw'] ?? ''));
        // The date and time the tenant must be out by come ONLY from the form's own fields - the one value the notice prints and the breach
        // stores (audit 2026-10-20 finding 7). A typed vacate_date= / vacate_time= line is ignored; every other typed line still wins.
        unset($figures['vacate_date'], $figures['vacate_time']);
        if (! empty($data['vacate_date'])) {
            $figures = $this->breaches->vacateFigures($data['vacate_date'], $data['vacate_time'] ?? null, (int) $breach->agency_id) + $figures;
        }
        if (trim((string) ($data['breach_summary'] ?? '')) !== '') {
            $figures += ['breach_summary' => trim($data['breach_summary'])];
        }

        if ($data['intent'] === 'preview') {
            $html = $this->notices->preview($template, $figures, $breach->lease, $breach, $request->user());

            return back()->withInput()->with('breach_preview', ['html' => $html, 'missing' => $this->notices->unfilled($html), 'template' => $template->name]);
        }

        try {
            $this->breaches->issueVacate($breach, $template, $request->user(), $figures, $data['vacate_date'] ?? null, $data['vacate_time'] ?? null);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.breaches.show', $breach->id)->with('success', 'Notice to vacate sent. The landlord has been told.');
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        $this->breaches->archive($this->find($request, $id), $request->user());

        return redirect()->route('corex.breaches.index')->with('success', 'Breach record archived.');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $breach = $this->find($request, $id);
        $this->breaches->restore($breach, $request->user());

        return redirect()->route('corex.breaches.show', $breach->id)->with('success', 'Breach record restored.');
    }

    /** The breach notice PDF (kind=breach) or the notice to vacate PDF (kind=vacate). */
    public function document(Request $request, int $id, string $kind)
    {
        $breach = $this->find($request, $id);
        $notice = $kind === 'vacate' ? $breach->vacateNotice : $breach->notice;
        $document = $notice?->document;
        abort_if(! $document || ! Storage::disk($document->disk)->exists($document->storage_path), 404);

        return Storage::disk($document->disk)->download($document->storage_path, $document->original_name);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function find(Request $request, int $id): LeaseBreach
    {
        $breach = LeaseBreach::query()->withTrashed()->findOrFail($id); // BelongsToAgency: another agency's id is a 404
        abort_unless(Lease::query()->withTrashed()->visibleTo($request->user())->whereKey($breach->lease_id)->exists(), 403);

        return $breach;
    }

    private function authoriseLease(Lease $lease, Request $request): void
    {
        abort_unless(Lease::query()->withTrashed()->visibleTo($request->user())->whereKey($lease->id)->exists(), 403);
    }

    /** The running leases among these ids that this user may see (own / branch / agency). Anything else is quietly dropped. */
    private function visibleActiveLeases(Request $request, array $ids)
    {
        return Lease::query()->visibleTo($request->user())->whereIn('leases.id', $ids)->where('status', Lease::STATUS_ACTIVE)
            ->with(['property', 'tenants.contact'])->get()->keyBy('id');
    }

    private function parseFigures(string $raw): array
    {
        $figures = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) !== '') {
                $figures[trim($key)] = trim($value);
            }
        }

        return $figures;
    }
}
