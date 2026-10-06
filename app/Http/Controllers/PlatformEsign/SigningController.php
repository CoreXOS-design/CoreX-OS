<?php

namespace App\Http\Controllers\PlatformEsign;

use App\Http\Controllers\Controller;
use App\Models\PlatformEsign\Signer;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Public, token-gated signing page (no login). The token is the authorisation. */
class SigningController extends Controller
{
    public function __construct(private EsignService $svc)
    {
    }

    private function signerOr404(string $token): Signer
    {
        $signer = $this->svc->resolve($token);
        abort_unless($signer && !$signer->document->trashed(), 404);

        return $signer;
    }

    public function show(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document->load(['attachments', 'signers']);
        $blocked = $this->svc->blockedReason($signer);
        if (!$blocked) {
            $this->svc->recordView($signer, $request->ip());
        }

        return response()->view('platform-esign.public.sign', [
            'signer' => $signer, 'doc' => $doc, 'blocked' => $blocked, 'token' => $token, 'consent' => EsignService::CONSENT,
            'myFields' => $doc->isPdf() ? collect($doc->fields_json)->where('role_key', $signer->role_key)->values() : collect(),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function sign(Request $request, string $token)
    {
        $this->signerOr404($token);
        $data = $request->validate([
            'typed_name' => 'required|string|max:255', 'id_number' => 'nullable|string|max:40',
            'signature' => 'nullable|string', 'fields' => 'nullable|array', 'fields.*' => 'nullable|string|max:500',
            'consent' => 'accepted',
        ], ['consent.accepted' => 'Tick the box to confirm you agree to sign electronically.']);
        try {
            $this->svc->sign($token, $data, $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['sign' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.sign.show', $token)->with('signed', true);
    }

    public function decline(Request $request, string $token)
    {
        $this->signerOr404($token);
        $reason = $request->validate(['reason' => 'required|string|max:490'])['reason'];
        try {
            $this->svc->decline($token, $reason, $request->ip());
        } catch (\DomainException $e) {
            return back()->withErrors(['sign' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.sign.show', $token);
    }

    public function page(Request $request, string $token, int $page)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document;
        abort_unless($doc->isPdf() && $page >= 0 && $page < $doc->page_count, 404);
        $path = $this->svc->pagePath('platform-esign/documents/' . $doc->id, $page);
        abort_unless(Storage::disk(EsignService::DISK)->exists($path), 404);

        return response()->file(Storage::disk(EsignService::DISK)->path($path), ['Cache-Control' => 'private, no-store']);
    }

    public function attachment(Request $request, string $token, int $attachment)
    {
        $signer = $this->signerOr404($token);
        $att = $signer->document->attachments()->findOrFail($attachment);
        abort_unless(Storage::disk(EsignService::DISK)->exists($att->stored_path), 404);

        return Storage::disk(EsignService::DISK)->download($att->stored_path, $att->original_name);
    }

    /** The signed copy, offered to a signer once the document is complete. */
    public function download(Request $request, string $token)
    {
        $signer = $this->signerOr404($token);
        $doc = $signer->document;
        abort_unless($doc->status === 'completed' && $doc->sealed_pdf_path && Storage::disk(EsignService::DISK)->exists($doc->sealed_pdf_path), 404);

        return Storage::disk(EsignService::DISK)->download($doc->sealed_pdf_path, \Illuminate\Support\Str::slug($doc->title) . '-signed.pdf');
    }
}
