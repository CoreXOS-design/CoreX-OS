<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\RmcpBoardLetter;
use App\Models\Compliance\RmcpBoardSignature;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\RmcpBoardApprovalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * RMCP board approval letter - the signed-in side (.ai/specs/compliance.md "RMCP board approval letter").
 * Send / resend / cancel / re-create the PDF need manage_rmcp_board_approval. A board member who is a CoreX user signs from
 * the panel (no permission needed beyond being on the open line). The emailed-link side is RmcpBoardSigningController.
 * Every version binding is agency-scoped (BelongsToAgency), so another agency's id is a 404.
 */
class RmcpBoardApprovalController extends Controller
{
    public function __construct(private RmcpBoardApprovalService $service)
    {
    }

    public function send(Request $request, RmcpVersion $version)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'effective_from'  => ['required', 'date', 'after_or_equal:today'],
            'next_review_due' => ['nullable', 'date', 'after:effective_from'],
        ], [
            'effective_from.after_or_equal' => 'The effective date cannot be in the past.',
            'next_review_due.after'         => 'The review date must be after the effective date.',
        ], ['effective_from' => 'effective date', 'next_review_due' => 'review date']);

        try {
            $result = $this->service->send(
                $version, Auth::user(), Carbon::parse($data['effective_from']),
                ! empty($data['next_review_due']) ? Carbon::parse($data['next_review_due']) : null
            );
        } catch (\DomainException $e) {
            return $this->back($version)->with('error', $e->getMessage());
        }

        $count = $result['letter']->signatures->count();
        if ($result['failed']) {
            return $this->back($version)->with('error', 'The letter is ready, but the email to ' . implode(', ', $result['failed'])
                . ' could not be sent. Press "Send again" next to their name.');
        }

        return $this->back($version)->with('success', "Sent to {$count} board member(s). Each of them gets an email with a link to sign.");
    }

    public function resend(RmcpVersion $version, RmcpBoardSignature $signature)
    {
        $this->authorizeManage();
        $this->ensureSignatureBelongs($version, $signature);

        try {
            $ok = $this->service->resend($signature, Auth::user());
        } catch (\DomainException $e) {
            return $this->back($version)->with('error', $e->getMessage());
        }

        return $ok
            ? $this->back($version)->with('success', "Sent again to {$signature->member_name}.")
            : $this->back($version)->with('error', "The email to {$signature->member_name} could not be sent just now. Please try again.");
    }

    public function cancel(RmcpVersion $version, RmcpBoardLetter $letter)
    {
        $this->authorizeManage();
        abort_unless((int) $letter->rmcp_version_id === (int) $version->id, 404);

        try {
            $this->service->cancel($letter, Auth::user());
        } catch (\DomainException $e) {
            return $this->back($version)->with('error', $e->getMessage());
        }

        return $this->back($version)->with('success', 'That letter is cancelled. Nothing was deleted; you can send a new one.');
    }

    public function regeneratePdf(RmcpVersion $version, RmcpBoardLetter $letter)
    {
        $this->authorizeManage();
        abort_unless((int) $letter->rmcp_version_id === (int) $version->id && $letter->isComplete(), 404);

        return $this->service->regeneratePdf($letter, Auth::user())
            ? $this->back($version)->with('success', 'The signed letter PDF is ready.')
            : $this->back($version)->with('error', 'The PDF could not be created just now. Please try again; if it keeps failing, tell your administrator.');
    }

    /** The signed letter - for whoever manages or approves the RMCP. */
    public function downloadPdf(RmcpVersion $version, RmcpBoardLetter $letter)
    {
        abort_unless(Auth::user()->hasAnyPermission(['manage_rmcp_board_approval', 'approve_rmcp']), 403);
        abort_unless((int) $letter->rmcp_version_id === (int) $version->id && $letter->isComplete() && $letter->signed_pdf_path, 404);
        abort_unless(Storage::disk('local')->exists($letter->signed_pdf_path), 404, 'The signed letter is missing from storage.');

        return Storage::disk('local')->download($letter->signed_pdf_path, "rmcp-v{$version->version_number}-board-approval-signed.pdf");
    }

    // ── A board member who is a CoreX user signs in the app ─────────────────

    public function signForm(Request $request, RmcpVersion $version)
    {
        $line = $this->service->openLineForUser($version, Auth::user());
        if (! $line) {
            return $this->back($version)->with('error', 'There is no letter waiting for your signature on this RMCP.');
        }

        $this->service->recordView($line, $request->ip(), $request->userAgent());
        $line->loadMissing('letter');

        return view('compliance.rmcp-board.sign', ['version' => $version, 'line' => $line, 'letter' => $line->letter]);
    }

    public function signSubmit(Request $request, RmcpVersion $version)
    {
        $line = $this->service->openLineForUser($version, Auth::user());
        if (! $line) {
            return $this->back($version)->with('error', 'There is no letter waiting for your signature on this RMCP.');
        }

        $data = $request->validate([
            'signature_type'           => ['required', 'in:drawn,typed'],
            'signature_data'           => ['required_if:signature_type,drawn', 'nullable', 'string'],
            'typed_name'               => ['required_if:signature_type,typed', 'nullable', 'string', 'max:200'],
            'declaration_acknowledged' => ['accepted'],
        ], ['declaration_acknowledged.accepted' => 'Please tick the box to confirm you have read the letter.']);

        try {
            $this->service->sign($line, $data['signature_type'], $data['signature_data'] ?? null, $data['typed_name'] ?? null,
                'in_app', $request->ip(), $request->userAgent(), Auth::user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->back($version)->with('success', 'Thank you. Your signature is saved.');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function authorizeManage(): void
    {
        abort_unless(Auth::user()->hasPermission('manage_rmcp_board_approval'), 403);
    }

    private function ensureSignatureBelongs(RmcpVersion $version, RmcpBoardSignature $signature): void
    {
        $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($signature->letter_id);
        abort_unless($letter && (int) $letter->rmcp_version_id === (int) $version->id, 404);
    }

    private function back(RmcpVersion $version)
    {
        return redirect()->to(route('compliance.rmcp.show', $version) . '#board-approval');
    }
}
