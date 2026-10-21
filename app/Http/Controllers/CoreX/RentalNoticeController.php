<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Services\Rentals\RentalNoticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. Agent picks a lease
 * (via the "Send notice" action on the Lease Hub), picks a template,
 * enters figures, previews, sends. List/show per BUILD_STANDARD §1a floor
 * — notices are sent, not edited after sending.
 */
class RentalNoticeController extends Controller
{
    /** The columns the list may be sorted on (anything else is ignored - the default applies). */
    private const SORTS = ['sent_at', 'notice_type', 'delivery_status', 'created_at'];

    /**
     * AT-495 - the "Notices sent" tab. List-screen floor: search (tenant name, property address, lease number, document name), sort (date sent
     * - default newest first -, type, delivery), filter (type, delivery status, date range, archived), pagination, real empty state.
     */
    public function index(Request $request): View
    {
        // Own / branch / agency visibility is the lease's (Lease::visibleTo) — a notice is only as visible as the lease it is about.
        $query = RentalNotice::query()->with(['lease.property', 'lease.tenants.contact', 'sentByUser', 'template'])
            ->whereIn('lease_id', Lease::query()->withTrashed()->visibleTo($request->user())->select('leases.id'));

        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('lease.property', fn ($p) => $p->withTrashed()->where(fn ($pp) => $pp->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('template', fn ($t) => $t->withTrashed()->where('name', 'like', "%{$search}%"));
                // a lease number: "12" or "#12" or "Lease #12"
                if (preg_match('/^(?:lease\s*)?#?\s*(\d+)$/i', $search, $m)) {
                    $q->orWhere('lease_id', (int) $m[1]);
                }
            });
        }

        if ($type = $request->get('notice_type')) {
            $query->where('notice_type', $type);
        }

        $status = (string) $request->get('status', '');
        if (in_array($status, [RentalNotice::DELIVERY_SENT, RentalNotice::DELIVERY_PARTIAL, RentalNotice::DELIVERY_NOT_SENT], true)) {
            $query->where('delivery_status', $status);
        } elseif ($status === 'unrecorded') {
            $query->whereNull('delivery_status');
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('sent_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('sent_at', '<=', $to);
        }

        // Audit 2026-10-18 (A-L7): only these columns may be sorted on - a free `?sort=` used to reach orderBy() as-is
        // (a bad name was a 500, a hidden column could be probed by ordering). Anything else falls back to the default.
        $sort = in_array($request->get('sort'), self::SORTS, true) ? $request->get('sort') : 'sent_at';
        $dir = $request->has('dir') ? ($request->get('dir') === 'asc' ? 'asc' : 'desc') : ($sort === 'sent_at' ? 'desc' : 'asc');
        $query->orderBy($sort, $dir)->orderByDesc('id');

        $notices = $query->paginate(25)->withQueryString();

        return view('corex.rental-notices.index', compact('notices', 'sort', 'dir', 'status'));
    }

    /** Direct-URL access by id is blocked unless the notice's lease is visible to this user (own / branch / agency). */
    private function authoriseLease(Lease $lease, Request $request): void
    {
        // withTrashed: a notice about a since-archived lease must still open for people who could see that lease.
        abort_unless(Lease::query()->withTrashed()->visibleTo($request->user())->whereKey($lease->id)->exists(), 403);
    }

    public function show(Request $request, RentalNotice $rentalNotice): View
    {
        $this->authoriseLease($rentalNotice->lease()->withoutGlobalScopes()->firstOrFail(), $request);
        $rentalNotice->load(['lease.property', 'template', 'sentByUser', 'document', 'deliveries.attemptedBy']);

        return view('corex.rental-notices.show', ['notice' => $rentalNotice]);
    }

    /** AT-495 - email the notice again to the people it was addressed to, from the agent's own mailbox. Logged on the lease. */
    public function resend(Request $request, RentalNotice $rentalNotice, RentalNoticeService $service): RedirectResponse
    {
        $this->authoriseLease($rentalNotice->lease()->withoutGlobalScopes()->firstOrFail(), $request);
        try {
            $deliveries = $service->resend($rentalNotice, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        $missed = $deliveries->reject(fn ($d) => $d->wasSent());
        if ($deliveries->isEmpty() || $missed->isNotEmpty()) {
            return back()->with('error', $deliveries->isEmpty()
                ? 'Nothing was sent: no one is linked to the lease on the side this notice was addressed to.'
                : 'Resent, but NOT sent to: ' . $missed->map(fn ($d) => ($d->recipient_name ?: ucfirst($d->recipient_role)) . ($d->status === 'failed' ? ' (the email failed)' : ' (no email address on file)'))->implode(', ') . '.');
        }

        return back()->with('success', 'Notice resent.');
    }

    public function archive(Request $request, RentalNotice $rentalNotice): RedirectResponse
    {
        $this->authoriseLease($rentalNotice->lease()->withoutGlobalScopes()->firstOrFail(), $request);
        abort_if($rentalNotice->lease_breach_id !== null, 404); // a breach notice is archived with its breach, on the breach screen
        $rentalNotice->delete();

        return redirect()->route('corex.rental-notices.index')->with('success', 'Notice archived.');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $notice = RentalNotice::onlyTrashed()->findOrFail($id); // BelongsToAgency: another agency's id is a 404
        $this->authoriseLease($notice->lease()->withoutGlobalScopes()->firstOrFail(), $request);
        $notice->restore();

        return redirect()->route('corex.rental-notices.show', $notice)->with('success', 'Notice restored.');
    }

    public function downloadDocument(Request $request, RentalNotice $rentalNotice)
    {
        $this->authoriseLease($rentalNotice->lease()->withoutGlobalScopes()->firstOrFail(), $request);
        $document = $rentalNotice->document;
        abort_if(!$document || !\Illuminate\Support\Facades\Storage::disk($document->disk)->exists($document->storage_path), 404);

        return \Illuminate\Support\Facades\Storage::disk($document->disk)->download($document->storage_path, $document->original_name);
    }

    /** The draft form, reached from the Lease Hub's "Send notice" action. */
    public function create(Request $request, Lease $lease): View
    {
        $this->authoriseLease($lease, $request);
        $templates = RentalNoticeTemplate::where('is_active', true)->orderBy('name')->get();

        return view('corex.rental-notices.create', [
            'lease' => $lease, 'templates' => $templates,
            'autoPlaceholders' => \App\Services\Rentals\RentalNoticeService::PLACEHOLDERS,
            'preview' => session('notice_preview'),
            'chosenTemplateId' => (int) $request->query('template'),
        ]);
    }

    public function store(Request $request, Lease $lease, RentalNoticeService $service): RedirectResponse
    {
        $this->authoriseLease($lease, $request);
        $data = $request->validate([
            'rental_notice_template_id' => ['required', \App\Rules\FoundInAgency::in('rental_notice_templates')],
            'figures_raw' => ['nullable', 'string', 'max:5000'],
            'send_to_tenant' => ['nullable', 'boolean'],
            'send_to_landlord' => ['nullable', 'boolean'],
            'intent' => ['nullable', 'in:preview,send'],
        ]);

        $template = RentalNoticeTemplate::findOrFail($data['rental_notice_template_id']);
        abort_unless($template->agency_id === $lease->agency_id, 404);

        $figures = [];
        foreach (preg_split('/\r?\n/', (string) ($data['figures_raw'] ?? '')) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $figures[$key] = trim($value);
            }
        }

        if (($data['intent'] ?? 'send') === 'preview') {
            $html = $service->preview($template, $figures, $lease, null, $request->user());

            return back()->withInput()->with('notice_preview', ['html' => $html, 'missing' => $service->unfilled($html), 'template' => $template->name]);
        }
        $toTenant = $request->boolean('send_to_tenant');
        $toLandlord = $request->boolean('send_to_landlord');
        if (!$toTenant && !$toLandlord) {
            return back()->withErrors(['recipients' => 'Choose at least one recipient — tenant or landlord.'])->withInput();
        }

        try {
            $notice = $service->send($lease, $template, $figures, $toTenant, $toLandlord, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // a placeholder still empty: the notice is never sent with a hole in it
            return back()->withInput()->withErrors($e->errors());
        }

        // AT-495 - never "sent" for someone it did not reach: say who it did NOT go to
        $missed = $notice->deliveries->reject(fn ($d) => $d->wasSent());
        if ($notice->delivery_status !== RentalNotice::DELIVERY_SENT) {
            return redirect()->route('corex.rental-notices.show', $notice)->with('error', 'The notice is filed on the lease, but NOT sent to '
                . ($missed->isEmpty() ? 'anyone - no one is linked to the lease on the side you chose' : $missed->map(fn ($d) => ($d->recipient_name ?: ucfirst($d->recipient_role)) . ($d->status === 'failed' ? ' (the email failed)' : ' (no email address on file)'))->implode(', ')) . '.');
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice sent.');
    }
}
