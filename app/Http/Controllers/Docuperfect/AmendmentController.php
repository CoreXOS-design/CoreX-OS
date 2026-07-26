<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * E-Sign V3 (ES-3 + ES-9) — Agent Review surface.
 *
 * Surfaces a diff view of a pending amendment (conditions added or
 * strikethroughs proposed) and provides three actions:
 *   - approve         → SignatureService::requeueAllPartiesForInitialing()
 *   - reject change   → SignatureService::rejectAmendmentChange()
 *   - reject document → SignatureService::rejectAmendmentDocument() (terminal)
 *
 * Routes (registered in routes/web.php):
 *   GET  /docuperfect/amendments/{amendment}/review
 *   POST /docuperfect/amendments/{amendment}/approve
 *   POST /docuperfect/amendments/{amendment}/reject-change
 *   POST /docuperfect/amendments/{amendment}/reject-document
 *
 * Permission: `manage_documents` (existing — gates the e-sign module).
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.6, §8
 */
class AmendmentController extends Controller
{
    // AT-267 H5 — approve/reject bound a DocumentAmendment by id (no global scope) and checked only
    // the manage_documents key, so any holder could act on ANY agency's amendment. Add the per-record
    // guard on the underlying document.
    use \App\Http\Controllers\Concerns\AuthorizesDocumentAccess;

    public function __construct(private readonly SignatureService $signatureService) {}

    public function review(Request $request, DocumentAmendment $amendment): Response
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }
        abort_unless($amendment->document !== null, 404);
        $this->guardDocument($amendment->document);

        $amendment->load(['template', 'document']);

        $conditions = DocumentCondition::where('amendment_id', $amendment->id)
            ->orderBy('block_id')
            ->orderBy('condition_number')
            ->get();

        $strikethroughs = DocumentClauseStrikethrough::where('amendment_id', $amendment->id)
            ->orderBy('clause_ref')
            ->get();

        return response()->view('docuperfect.amendments.review', [
            'amendment'      => $amendment,
            'template'       => $amendment->template,
            'document'       => $amendment->document,
            'conditions'     => $conditions,
            'strikethroughs' => $strikethroughs,
        ]);
    }

    public function approve(Request $request, DocumentAmendment $amendment): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }
        abort_unless($amendment->document !== null, 404);
        $this->guardDocument($amendment->document);

        $amendment->load('template');

        // Approve all condition rows under this amendment (audit stamp).
        DocumentCondition::where('amendment_id', $amendment->id)->update([
            'approved_by_agent_at'      => now(),
            'approved_by_agent_user_id' => $user->id,
        ]);
        DocumentClauseStrikethrough::where('amendment_id', $amendment->id)->update([
            'status'               => DocumentClauseStrikethrough::STATUS_APPROVED,
            'approved_by_agent_at' => now(),
        ]);

        // Kick off the initialing cascade.
        $this->signatureService->requeueAllPartiesForInitialing(
            $amendment->template,
            $amendment
        );

        // E-sign walk-fix FIX 4 — email the recipient that the agent
        // has acted. Resolution code drives the email's subject + body
        // tone (accepted / rejected / declined).
        $this->notifyRecipientOfResolution($amendment, $user, 'approved', $amendment->new_text);

        return redirect()->back()->with('status', 'Amendment approved. Initialing cascade started.');
    }

    public function rejectChange(Request $request, DocumentAmendment $amendment): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }
        abort_unless($amendment->document !== null, 404);
        $this->guardDocument($amendment->document);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->signatureService->rejectAmendmentChange(
            $amendment->loadMissing('template')->template,
            $amendment,
            $validated['reason'] ?? null
        );

        $this->notifyRecipientOfResolution($amendment, $user, 'rejected_change', null, $validated['reason'] ?? null);

        return redirect()->back()->with('status', 'Change rejected. Document returned to signing without this change.');
    }

    public function rejectDocument(Request $request, DocumentAmendment $amendment): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }
        abort_unless($amendment->document !== null, 404);
        $this->guardDocument($amendment->document);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->signatureService->rejectAmendmentDocument(
            $amendment->loadMissing('template')->template,
            $amendment,
            $validated['reason'] ?? null
        );

        $this->notifyRecipientOfResolution($amendment, $user, 'rejected_document', null, $validated['reason'] ?? null);

        return redirect()->back()->with('status', 'Document rejected. All parties notified. Terminal state.');
    }

    /**
     * E-sign walk-fix FIX 4 — send the recipient an email when the
     * agent resolves an amendment they raised. Drives the unlock for
     * the flag-blocks-signing surface: when the recipient returns to
     * the signing link, the lock evaluates the latest amendment
     * status and (when no flags remain pending) restores the sign /
     * initial buttons.
     *
     * Mail failures are logged but never block the resolution flow —
     * the amendment record + audit log remain authoritative.
     */
    private function notifyRecipientOfResolution(
        DocumentAmendment $amendment,
        \App\Models\User $agent,
        string $resolution,
        ?string $finalText = null,
        ?string $agentNote = null,
    ): void {
        $amendment->loadMissing(['template.document', 'amendedByRequest']);
        $recipient = $amendment->amendedByRequest;
        if ($recipient === null || empty($recipient->signer_email)) {
            return;
        }
        $documentName = $amendment->template?->document?->name ?? 'Document';
        $signingUrl   = route('signatures.external', $recipient->token);
        try {
            \Illuminate\Support\Facades\Mail::to($recipient->signer_email)
                ->send((new \App\Mail\Signatures\AmendmentResolvedByAgent(
                    recipientName: $recipient->signer_name ?? 'Signing party',
                    documentName:  $documentName,
                    agentName:     $agent->name ?? 'the agent',
                    clauseRef:     (string) ($amendment->flag_clause_ref ?? $amendment->section_reference ?? '—'),
                    resolution:    $resolution,
                    agentNote:     $agentNote,
                    finalText:     $finalText,
                    signingUrl:    $signingUrl,
                ))->fromAgent($agent));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send AmendmentResolvedByAgent email', [
                'amendment_id' => $amendment->id,
                'recipient_email' => $recipient->signer_email,
                'resolution'   => $resolution,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
