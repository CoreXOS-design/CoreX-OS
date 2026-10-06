<?php

namespace App\Http\Controllers\PlatformEsign;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PlatformEsign\Document;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use App\Services\PlatformEsign\Agreement\AgreementPdf;
use App\Services\PlatformEsign\Agreement\AgreementRecipientPrefill;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Http\Request;

/**
 * Owner (RR) side of the Subscription Agreement web document: one-click send, read-only review, countersign, audited reveal.
 * Owner-only by route middleware AND re-checked here (spec §5, §11).
 */
class AgreementController extends Controller
{
    public function __construct(private AgreementService $svc, private AgreementContent $content, private AgreementLayout $layout, private AgreementPdf $pdf)
    {
    }

    private function owner(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole(), 403);

        return $u;
    }

    private function webdoc(int $id): Document
    {
        $doc = Document::withTrashed()->with(['wording', 'agency', 'signers'])->findOrFail($id);
        abort_unless($doc->isWebdoc(), 404);

        return $doc;
    }

    public function create(Request $request)
    {
        $this->owner($request);
        $version = $this->content->ensureSeeded($request->user()->id);

        $agencies = Agency::orderBy('name')->get(['id', 'name']);
        $agencyId = (int) ($request->old('agency_id') ?? $request->query('agency'));
        $prefill = AgreementRecipientPrefill::forAgencies($agencies->pluck('id')->all());

        return view('platform-esign.agreement.send', [
            'agencies' => $agencies, 'agencyId' => $agencyId, 'prefill' => $prefill,
            'start' => $prefill[$agencyId] ?? ['name' => '', 'email' => '', 'cell' => ''],
            'version' => $version, 'expiryDays' => AgreementService::expiryDays(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->owner($request);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'cell' => 'nullable|string|max:40',
            'agency_id' => 'nullable|integer|exists:agencies,id', 'note' => 'nullable|string|max:490',
            'variation_text' => 'nullable|string|max:500', 'variation_amount' => 'nullable|string|max:14',
        ], ['name.required' => 'Enter the recipient’s full name.', 'email.required' => 'Enter the recipient’s email address.', 'email.email' => 'Enter a valid email address.']);
        try {
            $doc = $this->svc->send($data, $u->id);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['send' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.documents.show', $doc->id)->with('success', 'Sent to ' . $doc->signers->first()?->email . '. The link is below if you want to send it yourself as well.');
    }

    /** Read-only review of everything entered so far (account numbers masked). */
    public function preview(Request $request, int $id)
    {
        $this->owner($request);
        $doc = $this->webdoc($id);

        return view('platform-esign.agreement.review', $this->reviewData($doc, 'preview', false));
    }

    public function countersignForm(Request $request, int $id)
    {
        $this->owner($request);
        $doc = $this->webdoc($id);
        abort_unless(in_array($doc->status, ['awaiting_countersign', 'wetink_received'], true) && !$doc->trashed(), 404);
        if ($doc->status === 'wetink_received') {
            return view('platform-esign.agreement.countersign-wetink', [
                'doc' => $doc, 'files' => $doc->wetinkFiles()->get(), 'rr' => ['rr_name' => (string) $request->user()->name, 'rr_capacity' => 'Director', 'rr_date' => now()->toDateString()] + (array) $doc->rr_data,
                'versionLabel' => $doc->wording->label(),
            ]);
        }

        return view('platform-esign.agreement.review', $this->reviewData($doc, 'rr', true));
    }

    public function countersign(Request $request, int $id)
    {
        $u = $this->owner($request);
        $doc = $this->webdoc($id);
        $data = $request->validate([
            'values' => 'required|array', 'initials' => 'nullable|string|max:12', 'pages' => 'nullable|array',
        ]);
        try {
            $errors = $doc->status === 'wetink_received'
                ? $this->svc->countersignWetInk($doc, $u, $data['values'], $request->ip(), $request->userAgent())
                : $this->svc->countersign($doc, $u, $data['values'], (string) ($data['initials'] ?? ''), (array) ($data['pages'] ?? []), $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($errors) {
            return response()->json(['errors' => $errors, 'message' => 'Some details still need attention.'], 422);
        }
        session()->flash('success', 'Countersigned. The signed agreement has been sealed and emailed to both parties.');

        return response()->json(['ok' => true, 'redirect' => route('platform-esign.documents.show', $doc->id)]);
    }

    public function reveal(Request $request, int $id)
    {
        $u = $this->owner($request);
        $doc = $this->webdoc($id);
        $key = (string) $request->validate(['key' => 'required|string|max:40'])['key'];
        try {
            $value = $this->svc->reveal($doc, $u, $key, $request->ip());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['value' => $value])->header('Cache-Control', 'no-store');
    }

    /** One uploaded hand-signed file, streamed to an owner (never a public URL). */
    public function wetinkFile(Request $request, int $id, int $file)
    {
        $this->owner($request);
        $doc = $this->webdoc($id);
        $f = $doc->wetinkFiles()->findOrFail($file);
        abort_unless(\Illuminate\Support\Facades\Storage::disk(EsignService::DISK)->exists($f->stored_path), 404);

        return \Illuminate\Support\Facades\Storage::disk(EsignService::DISK)->download($f->stored_path, $f->original_name, ['Cache-Control' => 'no-store']);
    }

    private function reviewData(Document $doc, string $mode, bool $countersign): array
    {
        $ctx = $this->svc->context($doc, ['mask' => true]);
        if ($countersign) {
            $rr = $ctx['rr'];
            $user = request()->user();
            $rr += ['rr_name' => (string) $user->name, 'rr_capacity' => $rr['rr_capacity'] ?? 'Director', 'rr_date' => now()->toDateString()];
            $ctx['rr'] = $rr;
            $ctx['initials']['rr'] = $ctx['initials']['rr'] ?: \App\Services\PlatformEsign\SealService::initials((string) $user->name);
        }
        $layout = $this->layout->ensure($doc->wording);

        return [
            'doc' => $doc, 'pages' => $this->pdf->pages($doc->wording, $layout, $mode, $ctx), 'total' => $layout['total'],
            'versionLabel' => $doc->wording->label(), 'countersign' => $countersign, 'ctx' => $ctx,
            'defaultInitials' => $ctx['initials']['rr'] ?? '',
        ];
    }
}
