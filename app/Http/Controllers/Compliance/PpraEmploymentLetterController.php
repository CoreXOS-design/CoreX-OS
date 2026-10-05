<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\AgentSignatureService;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use App\Services\Compliance\PpraEmploymentLetterService;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPRA FFC renewal — Confirmation of Employment letter. My Portal (self-service)
 * side. .ai/specs/ppra-ffc-employment-letter.md
 *
 * Signing is self-service and fixed-order: the authenticated agent can only
 * ever start/sign a letter ABOUT THEMSELVES, and only ever sign the
 * principal block when they themselves are the letter's resolved
 * principal. Mirrors EvaluationCertificateController's guardSigner/unlock
 * shape (AgentSignatureService PIN mechanism), not the canon e-sign pipeline.
 */
class PpraEmploymentLetterController extends Controller
{
    /**
     * The real list/create UI lives embedded in My Portal > Documents
     * (resources/views/agent/portal.blade.php) — AgentPortalController@index
     * already loads the same "mine" + "awaiting my signature as principal"
     * data for that card. A bare GET to the index route (e.g. a bookmarked
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
            ->with('success', 'Letter generated — review and sign below.');
    }

    public function show(Request $request, PpraEmploymentLetter $letter)
    {
        $user = $request->user();
        $this->guardViewable($letter, $user);

        return view('compliance.ppra-employment-letters.show', [
            'letter'            => $letter,
            'agent'             => $letter->user,
            'principal'         => $letter->principal,
            'isAgent'           => (int) $letter->user_id === (int) $user->id,
            'isPrincipal'       => (int) $letter->principal_user_id === (int) $user->id
                                    && $user->hasPermission('ppra_employment_letters.sign_as_principal'),
            'canSignAgentNow'   => (int) $letter->user_id === (int) $user->id && $letter->isAwaitingAgentSignature(),
            'canSignPrincipalNow' => (int) $letter->principal_user_id === (int) $user->id
                                    && $user->hasPermission('ppra_employment_letters.sign_as_principal')
                                    && $letter->isAwaitingPrincipalSignature(),
            'savedSigConfigured' => app(AgentSignatureService::class)->isConfigured($user),
        ]);
    }

    public function signAsAgent(Request $request, PpraEmploymentLetter $letter, AgentSignatureService $signatures, PpraEmploymentLetterService $service)
    {
        $user = $this->guardSigner($request, $letter, $signatures);
        abort_unless((int) $letter->user_id === (int) $user->id, 403, 'You can only sign your own letter.');
        abort_unless($letter->isAwaitingAgentSignature(), 409, 'This letter is not awaiting your signature.');

        $contextKey = 'ppra-employment-letter:' . $letter->id . ':agent';
        if (($err = $this->unlock($request, $user, $signatures, $contextKey)) !== null) {
            return $err;
        }

        $service->signAsAgent($letter, $user, (string) $signatures->image($user, 'signature', $contextKey), (string) $request->ip());
        $signatures->lock($user, $contextKey);

        return redirect()->route('ppra-employment-letters.show', $letter)->with('success', 'Signed. Sent to the principal for signature.');
    }

    public function signAsPrincipal(Request $request, PpraEmploymentLetter $letter, AgentSignatureService $signatures, PpraEmploymentLetterService $service)
    {
        $user = $this->guardSigner($request, $letter, $signatures);
        abort_unless($user->hasPermission('ppra_employment_letters.sign_as_principal'), 403);
        abort_unless((int) $letter->principal_user_id === (int) $user->id, 403, 'You are not the principal for this letter.');
        abort_unless($letter->isAwaitingPrincipalSignature(), 409, 'This letter is not awaiting principal signature.');

        $contextKey = 'ppra-employment-letter:' . $letter->id . ':principal';
        if (($err = $this->unlock($request, $user, $signatures, $contextKey)) !== null) {
            return $err;
        }

        $service->signAsPrincipal($letter, $user, (string) $signatures->image($user, 'signature', $contextKey), (string) $request->ip());
        $signatures->lock($user, $contextKey);

        return redirect()->route('ppra-employment-letters.show', $letter)->with('success', 'Signed. The letter is now fully signed.');
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

        if ($letter->isSigned() && $letter->signed_pdf_path && Storage::exists($letter->signed_pdf_path)) {
            return $inline
                ? Storage::response($letter->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($letter->signed_pdf_path, $filename);
        }

        $agency = Agency::withoutGlobalScopes()->find($letter->agency_id);
        $agent  = $letter->user;
        $principal = $letter->principal;

        $pdfPath = $pdfService->generate($letter, $agent, $principal, $agency, $letter->agent_signature_image, null);

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

    private function guardSigner(Request $request, PpraEmploymentLetter $letter, AgentSignatureService $signatures): User
    {
        $user = $request->user();
        abort_if(Impersonation::actingAdminId() !== null, 403, 'Saved signatures are unavailable while acting as another user.');
        abort_unless($signatures->isConfigured($user), 422, 'Set up your saved signature and signing PIN in My Portal first.');

        return $user;
    }

    private function unlock(Request $request, User $user, AgentSignatureService $signatures, string $contextKey)
    {
        $pin = (string) $request->input('pin', '');
        if ($pin !== '') {
            if (! $signatures->verifyPinAndUnlock($user, $pin, $contextKey)) {
                return back()->with('error', 'Incorrect signing PIN.');
            }
        } elseif (! $signatures->isUnlocked($user, $contextKey)) {
            return back()->with('error', 'Enter your signing PIN to place your saved signature.');
        }

        return null;
    }
}
