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
    public function index(Request $request): View
    {
        // Own / branch / agency visibility is the lease's (Lease::visibleTo) — a notice is only as visible as the lease it is about.
        $query = RentalNotice::query()->with(['lease.property', 'sentByUser'])
            ->whereIn('lease_id', Lease::query()->visibleTo($request->user())->select('leases.id'));

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('lease.property', fn ($p) => $p->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        if ($type = $request->get('notice_type')) {
            $query->where('notice_type', $type);
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('sent_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('sent_at', '<=', $to);
        }

        $sort = $request->get('sort', 'sent_at');
        $direction = $sort === 'sent_at' ? 'desc' : 'asc';
        $query->orderBy($sort === 'sent_at' ? 'sent_at' : $sort, $direction);

        $notices = $query->paginate(25)->withQueryString();

        return view('corex.rental-notices.index', compact('notices'));
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
        $rentalNotice->load(['lease.property', 'template', 'sentByUser', 'document']);

        return view('corex.rental-notices.show', ['notice' => $rentalNotice]);
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

        return view('corex.rental-notices.create', ['lease' => $lease, 'templates' => $templates]);
    }

    public function store(Request $request, Lease $lease, RentalNoticeService $service): RedirectResponse
    {
        $this->authoriseLease($lease, $request);
        $data = $request->validate([
            'rental_notice_template_id' => ['required', 'exists:rental_notice_templates,id'],
            'figures_raw' => ['nullable', 'string', 'max:5000'],
            'send_to_tenant' => ['nullable', 'boolean'],
            'send_to_landlord' => ['nullable', 'boolean'],
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

        $toTenant = $request->boolean('send_to_tenant');
        $toLandlord = $request->boolean('send_to_landlord');
        if (!$toTenant && !$toLandlord) {
            return back()->withErrors(['recipients' => 'Choose at least one recipient — tenant or landlord.'])->withInput();
        }

        $notice = $service->send($lease, $template, $figures, $toTenant, $toLandlord, $request->user());

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Notice sent.');
    }
}
