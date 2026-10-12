<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\User;
use App\Services\Filing\FilingPropertyLinker;
use App\Services\PermissionService;
use App\Services\TitleDeeds\TitleDeedDestinations;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .ai/specs/title-deed-register.md — list, record, scan, and the owner-notice actions. Every route resolves the deed through
 * TitleDeed::visibleTo() (OWN / BRANCH / AGENCY): a direct URL by id is a 404 for anyone outside their scope.
 */
class TitleDeedController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private TitleDeedService $deeds,
        private TitleDeedNotifier $notifier,
    ) {}

    /* ═════════ List ═════════ */

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $query = $this->filtered($user, $filters);
        $this->applySort($query, $request);

        $deeds = $query->paginate(self::PER_PAGE)->withQueryString();
        $scope = PermissionService::getDataScope($user, 'title_deeds');

        return view('title-deeds.index', [
            'deeds' => $deeds,
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
            'isAll' => $scope === 'all',
            'branches' => $scope === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'agents' => $this->agentOptions($user, $scope, $filters['branch_id'] ?? null),
            'total' => TitleDeed::query()->visibleTo($user)->count(),
            'statuses' => TitleDeed::STATUS_LABELS,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = $this->filtered($user, $this->filters($request));
        $this->applySort($query, $request);

        $headers = ['Property', 'Owners', 'Agent', 'Branch', 'Attorney firm', 'Received', 'Status', 'Days waiting', 'Storage location', 'Collected', 'Collected by', 'Verified by'];

        return response()->streamDownload(function () use ($query, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
            fputcsv($out, $headers);
            $query->with(['agent', 'branch', 'owners.contact', 'receipt'])->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $d) {
                    fputcsv($out, array_map([$this, 'csvCell'], [
                        $d->property_display,
                        $d->owners->map->display_name->implode('; '),
                        $d->agent?->name,
                        $d->branch?->name,
                        $d->attorney_firm,
                        $d->received_at?->format('Y-m-d'),
                        $d->trashed() ? 'Archived' : $d->status_label,
                        $d->days_waiting,
                        $d->storage_location,
                        $d->collected_at?->format('Y-m-d H:i'),
                        $d->collected_by_name,
                        $d->receipt?->methodLabel(),
                    ]));
                }
            });
            fclose($out);
        }, 'title-deeds-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ═════════ Create / edit ═════════ */

    public function create(Request $request)
    {
        return view('title-deeds.form', $this->formData($request->user(), null));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $deed = $this->deeds->create($user, $this->validated($request));

        return redirect()->route('title-deeds.show', $deed->id)->with('success', 'Title deed logged.');
    }

    public function edit(Request $request, int $id)
    {
        $deed = $this->find($request, $id);
        if ($deed->trashed()) {
            return redirect()->route('title-deeds.show', $deed->id)->with('error', 'This title deed is archived. Restore it before editing.');
        }

        return view('title-deeds.form', $this->formData($request->user(), $deed));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);

        try {
            $data = $deed->isCollected()
                ? $request->validate(['notes' => ['nullable', 'string', 'max:5000']])
                : $this->validated($request);
            $this->deeds->update($request->user(), $deed, $data);
        } catch (TitleDeedException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.show', $deed->id)->with('success', 'Title deed updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $this->deeds->archive($request->user(), $deed);

        return redirect()->route('title-deeds.index')->with('success', 'Title deed archived.');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id, withTrashed: true);
        $this->deeds->restore($request->user(), $deed);

        return redirect()->route('title-deeds.show', $deed->id)->with('success', 'Title deed restored.');
    }

    /* ═════════ Record ═════════ */

    public function show(Request $request, int $id)
    {
        $deed = $this->find($request, $id, withTrashed: true);
        $deed->load(['agent', 'branch', 'creator', 'scan', 'receipt.recordedBy']);

        $recipients = $this->notifier->recipients($deed)->map(fn ($r) => [
            'name' => $r['owner']->display_name,
            'archived' => $r['contact'] === null,
            'email' => $r['email'] ? TitleDeedDestinations::maskEmail($r['email']) : null,
            'mobile' => $r['wa']['masked'] ?? null,
        ]);

        $events = $deed->events()->with('user:id,name')->limit(150)->get();
        $prepared = $events->where('channel', TitleDeedEvent::CHANNEL_WHATSAPP)->where('status', TitleDeedEvent::STATUS_PREPARED)->values();

        return view('title-deeds.show', [
            'deed' => $deed,
            'recipients' => $recipients,
            'events' => $events,
            'prepared' => $prepared,
            'audit' => $deed->audit()->with('user:id,name')->limit(300)->get(),
            'settings' => [
                'pin_required' => TitleDeedSetting::pinRequiredFor($deed->agency_id),
                'locked' => $deed->pin_locked_at !== null,
                'pin_issued' => $deed->pin_version > 0 && $deed->pin_encrypted !== null,
            ],
            'can' => [
                'edit' => $request->user()->hasPermission('title_deeds.edit'),
                'archive' => $request->user()->hasPermission('title_deeds.archive'),
                'notify' => $request->user()->hasPermission('title_deeds.notify'),
                'handover' => $request->user()->hasPermission('title_deeds.handover'),
            ],
        ]);
    }

    /* ═════════ Scan and handover files ═════════ */

    public function uploadScan(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $request->validate([
            'scan' => ['required', 'file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
        ], ['scan.required' => 'Choose a file to upload.', 'scan.mimes' => 'The scan must be a PDF or an image (JPG, PNG, WEBP or HEIC).', 'scan.max' => 'The scan is too large (25 MB at most).']);

        try {
            $this->deeds->attachScan($request->user(), $deed, $request->file('scan'));
        } catch (TitleDeedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.show', $deed->id)->with('success', 'Scan saved.');
    }

    public function downloadScan(Request $request, int $id)
    {
        $deed = $this->find($request, $id, withTrashed: true);
        $doc = $deed->scan;
        abort_unless($doc && Storage::disk($doc->disk ?: 'local')->exists($doc->storage_path), 404);

        TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SCAN_DOWNLOADED, 'Scan downloaded.', null, null, $request->user());

        return Storage::disk($doc->disk ?: 'local')->download($doc->storage_path, $doc->original_name);
    }

    /** The signature, authority letter or collector ID photo recorded at handover. */
    public function receiptFile(Request $request, int $id, string $kind)
    {
        abort_unless(in_array($kind, ['signature', 'letter', 'id-photo'], true), 404);
        $deed = $this->find($request, $id, withTrashed: true);
        $receipt = $deed->receipt;
        abort_unless($receipt, 404);

        if ($kind === 'signature') {
            abort_unless($receipt->signature_path && Storage::disk('local')->exists($receipt->signature_path), 404);

            return Storage::disk('local')->response($receipt->signature_path, 'signature.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
        }

        $docId = $kind === 'letter' ? $receipt->authority_letter_document_id : $receipt->collector_id_photo_document_id;
        $doc = $docId ? \App\Models\Document::query()->where('source_id', $deed->id)->find($docId) : null;
        abort_unless($doc && Storage::disk($doc->disk ?: 'local')->exists($doc->storage_path), 404);

        return Storage::disk($doc->disk ?: 'local')->download($doc->storage_path, $doc->original_name);
    }

    /* ═════════ Owner notices and the PIN ═════════ */

    public function notify(Request $request, int $id): RedirectResponse
    {
        return $this->noticeAction($request, $id, fn ($user, $deed) => $this->notifier->notifyOwners($user, $deed), 'Owner notice');
    }

    public function resendPin(Request $request, int $id): RedirectResponse
    {
        return $this->noticeAction($request, $id, fn ($user, $deed) => $this->notifier->resendPin($user, $deed), 'PIN resent');
    }

    public function newPin(Request $request, int $id): RedirectResponse
    {
        return $this->noticeAction($request, $id, fn ($user, $deed) => $this->notifier->issueNewPin($user, $deed), 'New PIN');
    }

    /** Send the user to WhatsApp with the message ready. The PIN is read here and never printed on a CoreX page. */
    public function openWhatsapp(Request $request, int $id, int $event)
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedEvent::query()->where('title_deed_id', $deed->id)->findOrFail($event);
        $link = $this->notifier->whatsappLink($deed, $row);
        if (! $link) {
            return redirect()->route('title-deeds.show', $deed->id)->with('error', 'That WhatsApp message cannot be opened - the owner has no mobile number stored any more.');
        }

        return redirect()->away($link)->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function confirmWhatsapp(Request $request, int $id, int $event): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedEvent::query()->where('title_deed_id', $deed->id)->findOrFail($event);

        try {
            $this->notifier->confirmWhatsapp($request->user(), $deed, $row);
        } catch (TitleDeedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded as sent.');
    }

    /* ═════════ Pickers ═════════ */

    public function searchProperties(Request $request, FilingPropertyLinker $linker)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $linker->candidates($q, $request->user())->map(fn (Property $p) => [
            'id' => $p->id,
            'address' => $p->buildDisplayAddress(),
            'ref' => $p->property_number,
            'status' => (string) $p->status,
            'agent' => $p->agent?->name,
        ])->values();

        return response()->json(['results' => $results]);
    }

    /** What a picked property already tells us: its agent, branch and the owners CoreX holds for it. */
    public function propertySuggestions(Request $request, int $property, FilingPropertyLinker $linker)
    {
        $model = Property::query()->visibleTo($request->user())->findOrFail($property);

        return response()->json([
            'agent_id' => $model->agent_id,
            'branch_id' => $model->branch_id,
            'owners' => $linker->sellerCandidates($model)->map(fn (Contact $c) => $this->contactOption($c))->values(),
        ]);
    }

    public function searchContacts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = Contact::query()->search($q)->orderBy('first_name')->orderBy('last_name')->limit(15)->get()
            ->map(fn (Contact $c) => $this->contactOption($c))->values();

        return response()->json(['results' => $results]);
    }

    /* ═════════ helpers ═════════ */

    private function noticeAction(Request $request, int $id, \Closure $do, string $label): RedirectResponse
    {
        $deed = $this->find($request, $id);
        try {
            $summary = $do($request->user(), $deed);
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.show', $deed->id)->with('error', $e->getMessage());
        }

        $parts = [];
        if ($summary['sent']) {
            $parts[] = $summary['sent'] . ' emailed';
        }
        if ($summary['prepared']) {
            $parts[] = $summary['prepared'] . ' WhatsApp ready to send';
        }
        if ($summary['failed']) {
            $parts[] = $summary['failed'] . ' failed';
        }
        if ($summary['skipped']) {
            $parts[] = $summary['skipped'] . ' skipped';
        }

        $redirect = redirect()->route('title-deeds.show', $deed->id);
        $text = $label . ': ' . ($parts ? implode(', ', $parts) : 'nothing could be sent') . '.';

        return ($summary['failed'] > 0 || $summary['sent'] + $summary['prepared'] === 0)
            ? $redirect->with('error', $text)
            : $redirect->with('success', $text);
    }

    /** Resolve a deed through the user's OWN / BRANCH / AGENCY scope - 404 for everything else. */
    private function find(Request $request, int $id, bool $withTrashed = false): TitleDeed
    {
        $query = TitleDeed::query()->visibleTo($request->user());
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }

    private function filters(Request $request): array
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_merge(array_keys(TitleDeed::STATUS_LABELS), ['archived']))],
            'branch_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            'search' => isset($v['search']) ? trim($v['search']) : null,
            'status' => $v['status'] ?? null,
            'branch_id' => $v['branch_id'] ?? null,
            'agent_id' => $v['agent_id'] ?? null,
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
        ];
    }

    private function filtered(User $user, array $f)
    {
        $query = TitleDeed::query()->visibleTo($user)->with(['agent', 'branch', 'owners.contact', 'receipt']);

        if ($f['status'] === 'archived') {
            $query->onlyTrashed();
        } elseif ($f['status']) {
            $query->where('title_deeds.status', $f['status']);
        }
        if ($f['branch_id'] && PermissionService::getDataScope($user, 'title_deeds') === 'all') {
            $query->where('title_deeds.branch_id', $f['branch_id']);
        }
        if ($f['agent_id']) {
            $query->where('title_deeds.agent_id', $f['agent_id']);
        }
        if ($f['from']) {
            $query->whereDate('title_deeds.received_at', '>=', $f['from']);
        }
        if ($f['to']) {
            $query->whereDate('title_deeds.received_at', '<=', $f['to']);
        }

        return $query->search($f['search']);
    }

    /** Default: oldest uncollected first. Any column header sorts; an unknown key falls back to the default. */
    private function applySort($query, Request $request): void
    {
        $key = (string) $request->get('sort', '');
        $dir = strtolower((string) $request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (! array_key_exists($key, TitleDeed::SORTABLE)) {
            $query->orderByRaw("(title_deeds.status = 'collected') asc")->orderBy('title_deeds.received_at')->orderBy('title_deeds.id');

            return;
        }

        if ($key === 'agent') {
            $query->leftJoin('users as sort_agent', 'sort_agent.id', '=', 'title_deeds.agent_id')
                ->select('title_deeds.*')->orderBy('sort_agent.name', $dir);
        } elseif ($key === 'waiting') {
            // More days waiting = an EARLIER received date.
            $query->orderBy('title_deeds.received_at', $dir === 'desc' ? 'asc' : 'desc');
        } else {
            $query->orderBy(TitleDeed::SORTABLE[$key], $dir);
        }
        $query->orderBy('title_deeds.id');
    }

    private function agentOptions(User $user, ?string $scope, $branchId)
    {
        $q = User::query()->where('is_active', 1)->orderBy('name');
        if ($scope !== 'all' && $user->effectiveBranchId()) {
            $q->where('branch_id', $user->effectiveBranchId());
        } elseif ($branchId) {
            $q->where('branch_id', $branchId);
        }

        return $q->get(['id', 'name']);
    }

    private function formData(User $user, ?TitleDeed $deed): array
    {
        $scope = PermissionService::getDataScope($user, 'title_deeds');
        $owners = $deed
            ? TitleDeedOwner::query()->where('title_deed_id', $deed->id)->get()->map(fn ($o) => ['id' => (int) $o->contact_id, 'name' => $o->display_name, 'detail' => ''])->values()
            : collect();

        // After a failed save, show what was picked (names are looked up again from the ids that came back).
        $oldOwnerIds = collect(session()->getOldInput('owner_ids', []))->map(fn ($i) => (int) $i)->filter()->unique()->values();
        if ($oldOwnerIds->isNotEmpty()) {
            $names = Contact::withoutGlobalScopes()->where('agency_id', $user->effectiveAgencyId())->whereIn('id', $oldOwnerIds)->get()->keyBy('id');
            $owners = $oldOwnerIds->map(fn ($id) => ['id' => $id, 'name' => isset($names[$id]) ? trim(($names[$id]->first_name ?? '') . ' ' . ($names[$id]->last_name ?? '')) : 'Contact #' . $id, 'detail' => ''])->values();
        }
        $oldPropertyId = (int) session()->getOldInput('property_id', 0);
        $oldProperty = $oldPropertyId ? Property::query()->visibleTo($user)->find($oldPropertyId) : null;

        return [
            'deed' => $deed,
            'ownersInit' => $owners,
            'propertyInit' => $oldProperty
                ? ['id' => $oldProperty->id, 'address' => $oldProperty->buildDisplayAddress()]
                : ($deed ? ['id' => $deed->property_id, 'address' => $deed->property_display] : null),
            'agents' => $this->agentOptions($user, $scope, null),
            'branches' => $scope === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : Branch::query()->where('id', $user->effectiveBranchId())->get(['id', 'name']),
            'defaultAgentId' => $deed?->agent_id ?? $user->id,
            'defaultBranchId' => $deed?->branch_id ?? $user->effectiveBranchId(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'owner_ids' => ['required', 'array', 'min:1'],
            'owner_ids.*' => ['integer'],
            'agent_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'attorney_firm' => ['nullable', 'string', 'max:255'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'property_id.required' => 'Pick the property from the list.',
            'owner_ids.required' => 'Add at least one owner.',
            'owner_ids.min' => 'Add at least one owner.',
            'received_at.required' => 'Enter the date the deed was received.',
            'received_at.before_or_equal' => 'The received date cannot be in the future.',
        ]);

        return $data;
    }

    private function contactOption(Contact $c): array
    {
        $email = TitleDeedDestinations::emailFor($c);
        $wa = TitleDeedDestinations::whatsappFor($c);

        return [
            'id' => $c->id,
            'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: 'Contact #' . $c->id,
            'detail' => implode(' · ', array_filter([$email ? TitleDeedDestinations::maskEmail($email) : null, $wa['masked'] ?? null])),
        ];
    }

    /** Spreadsheet formula injection: a cell that starts with = + - @ is made inert. */
    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }

        return $text;
    }
}
