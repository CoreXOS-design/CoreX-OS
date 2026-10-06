<?php

namespace App\Http\Controllers\PlatformEsign;

use App\Http\Controllers\Controller;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Initial;
use App\Models\PlatformEsign\Signer;
use App\Services\PlatformEsign\Agreement\AgreementConflict;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use App\Services\PlatformEsign\Agreement\AgreementPdf;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, token-gated recipient side of the Subscription Agreement web document (spec §11.7). No login: the token is the
 * authorisation, and it only ever resolves the AGENCY signer (r1) of a web document — never RR's, never another document type.
 */
class AgreementSigningController extends Controller
{
    public function __construct(private AgreementService $svc, private AgreementLayout $layout, private AgreementPdf $pdf, private EsignService $esign)
    {
    }

    private function signerOr404(string $token): Signer
    {
        $signer = Signer::where('token', $token)->where('role_key', 'r1')->first();
        abort_unless($signer, 404);
        $doc = $signer->document;
        abort_unless($doc && $doc->isWebdoc() && !$doc->trashed(), 404);

        return $signer;
    }

    public function show(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document->load(['wording', 'agency']);
        $blocked = $this->svc->blockedReason($doc, $signer);
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];

        if ($blocked) {
            return response()->view('platform-esign.agreement.message', [
                'doc' => $doc, 'signer' => $signer, 'message' => $blocked, 'token' => $token,
                'canDownload' => $doc->status === 'completed' && $doc->sealed_pdf_path,
                'canReplaceUpload' => $doc->status === 'wetink_received',
                'files' => $doc->status === 'wetink_received' ? $doc->wetinkFiles()->whereNull('superseded_at')->get() : collect(),
            ])->withHeaders($headers);
        }

        $this->esign->recordView($signer, $request->ip());
        $errors = (array) session('agr_errors', []);
        $ctx = $this->svc->context($doc, ['locked' => false, 'errors' => $errors]);
        $layout = $this->layout->ensure($doc->wording);
        $pages = $this->pdf->pages($doc->wording, $layout, 'form', $ctx);

        return response()->view('platform-esign.agreement.sign', [
            'doc' => $doc, 'signer' => $signer, 'token' => $token, 'pages' => $pages, 'total' => $layout['total'],
            'versionLabel' => $doc->wording->label(), 'ctx' => $ctx,
            'done' => Initial::where('signer_id', $signer->id)->pluck('page_no')->all(),
            'initialsSuggestion' => $signer->initials ?: \App\Services\PlatformEsign\SealService::initials((string) $signer->name),
            'consent' => EsignService::CONSENT, 'rev' => (int) $doc->form_rev,
        ])->withHeaders($headers);
    }

    /** Autosave. */
    public function save(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document;
        $data = $request->validate(['rev' => 'required|integer|min:0', 'values' => 'required|array']);
        try {
            $res = $this->svc->save($doc, $signer, $data['values'], (int) $data['rev']);
        } catch (AgreementConflict $e) {
            return response()->json(['conflict' => true, 'message' => $e->getMessage(), 'rev' => $e->rev, 'values' => $this->safeValues($e->values)], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($res + ['saved_at' => now()->format('H:i:s')]);
    }

    public function initials(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $data = $request->validate(['initials' => 'required|string|max:12']);
        try {
            $ini = $this->svc->setInitials($signer->document, $signer, $data['initials']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['initials' => $ini]);
    }

    public function initialPage(Request $request, string $token, int $page)
    {
        $signer = $this->signerOr404($token);
        try {
            $count = $this->svc->initialPage($signer->document, $signer, $page, $request->ip());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['page' => $page, 'done' => $count, 'initials' => $signer->fresh()->initials]);
    }

    public function submit(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document;
        $data = $request->validate([
            'values' => 'required|array', 'id_number' => 'nullable|string|max:40', 'consent' => 'nullable',
        ]);
        try {
            $errors = $this->svc->submit($doc, $signer, $data['values'], ['id_number' => $data['id_number'] ?? null, 'consent' => $data['consent'] ?? null], $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($errors) {
            return response()->json(['errors' => $errors, 'message' => 'Some details still need attention.'], 422);
        }

        return response()->json(['ok' => true, 'redirect' => route('platform-esign.agreement.show', $token)]);
    }

    /** Printable copy to sign by hand (spec §11.8). */
    public function wetCopy(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document->load('wording');
        try {
            $pdf = $this->svc->wetCopy($doc, $signer, $request->ip());
        } catch (\DomainException $e) {
            return redirect()->route('platform-esign.agreement.show', $token)->with('agr_notice', $e->getMessage());
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="CoreX-OS-Subscription-Agreement-' . $doc->contract_ref . '-to-sign.pdf"', 'Cache-Control' => 'no-store']);
    }

    /** The hand-signed copy comes back (pdf/jpg/png, ≤ 10 MB each). */
    public function upload(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $request->validate(['files' => 'required|array|max:' . AgreementService::WET_MAX_FILES, 'files.*' => 'file|max:' . AgreementService::WET_MAX_KB],
            ['files.required' => 'Choose the signed pages to upload (PDF, JPG or PNG).', 'files.*.max' => 'Each file must be 10 MB or smaller.', 'files.*.file' => 'One of the files could not be uploaded. Try again.']);
        try {
            $n = $this->svc->uploadWetInk($signer->document, $signer, $request->file('files', []), $request->ip());
        } catch (\DomainException $e) {
            return redirect()->route('platform-esign.agreement.show', $token)->withErrors(['upload' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.agreement.show', $token)->with('agr_notice', $n . ' file' . ($n === 1 ? '' : 's') . ' received. ' . \App\Services\PlatformEsign\Agreement\AgreementCompany::for($signer->document)->legalName() . ' will countersign and email you the signed copy.');
    }

    /** The signed copy, offered to the recipient once completed. */
    public function download(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document;
        abort_unless($doc->status === 'completed' && $doc->sealed_pdf_path && Storage::disk(EsignService::DISK)->exists($doc->sealed_pdf_path), 404);

        return Storage::disk(EsignService::DISK)->download($doc->sealed_pdf_path, 'CoreX-OS-Subscription-Agreement-' . $doc->contract_ref . '-signed.pdf');
    }

    /** Values safe to hand back to the browser (never RR-side). */
    private function safeValues(array $values): array
    {
        return array_intersect_key($values, array_flip(AgreementFields::recipientKeys()));
    }
}
