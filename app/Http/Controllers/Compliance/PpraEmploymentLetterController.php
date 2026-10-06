<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Compliance\PpraEmploymentLetterFile;
use App\Models\User;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use App\Services\Compliance\PpraEmploymentLetterService;
use App\Services\Compliance\PractitionerFfcRosterService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPRA FFC renewal — Confirmation of Employment letter. My Portal (self-service)
 * side. .ai/specs/ppra-ffc-employment-letter.md (§20 — wet-ink flow)
 *
 * The authenticated agent can only ever start a letter ABOUT THEMSELVES, print it, sign it in wet ink and upload the
 * signed copy of THEIR OWN letter. There is no electronic signing any more. The upload and the scan download go
 * through PpraEmploymentLetterService::attachSignedCopy() / streamSignedCopy() — the same single write/read path the
 * Admin register uses, so both screens show one and the same record.
 */
class PpraEmploymentLetterController extends Controller
{
    /**
     * The real list/create UI lives embedded in My Portal > Documents
     * (resources/views/agent/portal.blade.php) — AgentPortalController@index
     * already loads the same "mine" data for that card. A bare GET to the index route (e.g. a bookmarked
     * link, or a redirect target) lands back on that same screen rather than
     * duplicating a second full list page nobody asked for.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('agent.portal')->withFragment('documents');
    }

    public function store(Request $request, PpraEmploymentLetterService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.create'), 403);
        abort_unless($user->hasPermission(PractitionerFfcRosterService::LETTER_PERMISSION), 403);

        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId());
        abort_unless($agency, 422, 'No agency context.');

        $missing = $service->missingFieldsFor($user, $agency);
        if ($missing !== []) {
            return back()->with('error', 'Some information is missing before you can start this letter — see the list below.');
        }

        $validated = $request->validate([
            'principal_user_id' => ['nullable', 'integer'],
        ]);

        $letter = $service->create($user, $user, $validated['principal_user_id'] ?? null);

        return redirect()->route('ppra-employment-letters.show', $letter)
            ->with('success', 'Letter generated — print it, sign it, then upload the signed copy.');
    }

    public function show(Request $request, PpraEmploymentLetter $letter)
    {
        $user = $request->user();
        $this->guardViewable($letter, $user);

        $letter->load(['files.uploader']);

        return view('compliance.ppra-employment-letters.show', [
            'letter'    => $letter,
            'agent'     => $letter->user,
            'principal' => $letter->principal,
            'isAgent'   => (int) $letter->user_id === (int) $user->id,
            'canUpload' => (int) $letter->user_id === (int) $user->id,
            'files'     => $letter->files,
        ]);
    }

    /** Upload the wet-ink signed copy of the viewer's OWN letter — spec §20. */
    public function uploadSignedCopy(Request $request, PpraEmploymentLetter $letter, PpraEmploymentLetterService $service): RedirectResponse
    {
        $user = $request->user();
        $this->guardViewable($letter, $user);
        abort_unless((int) $letter->user_id === (int) $user->id, 403, 'You can only upload the signed copy of your own letter.');

        $request->validate([
            'signed_copy' => ['required', 'file', 'mimes:' . PpraEmploymentLetterService::UPLOAD_MIMES, 'max:' . PpraEmploymentLetterService::MAX_UPLOAD_KB],
        ], [
            'signed_copy.required' => 'Choose the signed letter to upload.',
            'signed_copy.mimes'    => 'The signed copy must be a PDF, JPG or PNG file.',
            'signed_copy.max'      => 'The signed copy must be 10 MB or smaller.',
            'signed_copy.uploaded' => 'The file could not be uploaded — it may be larger than 10 MB.',
        ]);

        $service->attachSignedCopy($letter, $request->file('signed_copy'), $user, PpraEmploymentLetterFile::VIA_PORTAL);

        // From the letter page → stay on it. From the My Portal row → back to My Portal with the PPRA pane open.
        if (rtrim(strtok(url()->previous(), '?#'), '/') === rtrim(route('ppra-employment-letters.show', $letter), '/')) {
            return back()->with('success', 'Signed copy uploaded and filed.');
        }

        return redirect()->route('agent.portal')->withFragment('documents')
            ->with('success', 'Signed copy uploaded and filed.')->with('ppra_letter_flash', true);
    }

    /** Stream one signed copy (current or superseded) of a letter the viewer may see. */
    public function signedCopy(Request $request, PpraEmploymentLetter $letter, int $file, PpraEmploymentLetterService $service): Response
    {
        $this->guardViewable($letter, $request->user());

        $record = $letter->files()->whereKey($file)->firstOrFail();

        return $service->streamSignedCopy($record, $request->boolean('inline'));
    }

    public function cancel(Request $request, PpraEmploymentLetter $letter, PpraEmploymentLetterService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless((int) $letter->user_id === (int) $user->id, 403);

        $service->cancel($letter);

        return redirect()->route('ppra-employment-letters.index')->with('success', 'Letter archived.');
    }

    public function download(Request $request, PpraEmploymentLetter $letter, PpraEmploymentLetterPdfService $pdfService): Response
    {
        $user = $request->user();
        $this->guardViewable($letter, $user);

        $filename = 'PPRA-Confirmation-of-Employment-' . ($letter->user?->name ? str_replace(' ', '-', $letter->user->name) : $letter->id) . '.pdf';
        $inline = $request->boolean('inline');

        // LEGACY: a letter signed through the retired PIN ceremony keeps the PDF baked at the time, untouched.
        if ($letter->isSigned() && $letter->signed_pdf_path && Storage::exists($letter->signed_pdf_path)) {
            return $inline
                ? Storage::response($letter->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($letter->signed_pdf_path, $filename);
        }

        $agency = Agency::withoutGlobalScopes()->find($letter->agency_id);

        // Printed for wet-ink signing: signature lines stay empty.
        $pdfPath = $pdfService->generate($letter, $letter->user, $letter->principal, $agency, null, null);

        return response()->download($pdfPath, $filename, [], $inline ? 'inline' : 'attachment')->deleteFileAfterSend(true);
    }

    /** Anyone who may legitimately view this letter: the agent, the resolved principal, or an in-scope admin/BM. */
    private function guardViewable(PpraEmploymentLetter $letter, User $user): void
    {
        $isAgent = (int) $letter->user_id === (int) $user->id;
        $isPrincipal = (int) $letter->principal_user_id === (int) $user->id;
        $inAdminScope = PpraEmploymentLetter::where('id', $letter->id)->visibleTo($user)->exists();

        abort_unless($isAgent || $isPrincipal || $inAdminScope, 404);
    }
}
