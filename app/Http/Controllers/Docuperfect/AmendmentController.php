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
    public function __construct(private readonly SignatureService $signatureService) {}

    public function review(Request $request, DocumentAmendment $amendment): Response
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }

        $amendment->load(['template.document', 'document', 'amendedByRequest']);

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
            // AT-302 Phase 1 — render THE DOCUMENT with the flagged clause
            // highlighted in place + the recipient's note attached, so the agent
            // reviews in context ("means nothing without the document").
            'flaggedDocumentHtml' => $this->buildFlaggedDocumentHtml($amendment),
        ]);
    }

    public function approve(Request $request, DocumentAmendment $amendment): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_documents')) {
            abort(403);
        }

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
    /**
     * AT-302 Phase 1 — render the document's stored merged_html with the flagged
     * clause highlighted in place and the recipient's note attached at the clause.
     * The clause is located by its number (flag_clause_ref) against the CDS
     * .corex-clause / .corex-clause-number structure. Read-only display for the
     * agent review screen; never mutates the stored document.
     */
    private function buildFlaggedDocumentHtml(DocumentAmendment $amendment): string
    {
        $document = $amendment->template?->document ?? $amendment->document;
        // ESIGN-WETINK Phase 1b — the amendment review renders the ONE canonical
        // artifact (the expanded, ink-bearing document every party saw), not the
        // raw un-expanded merged_html, so the flagged clause is highlighted on the
        // SAME rendering as the ceremony. Falls back to merged_html only when no
        // canonical body composes.
        $merged = '';
        if ($amendment->template) {
            $merged = app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)
                ->forDisplay($amendment->template);
        }
        if (trim($merged) === '') {
            $merged = (string) ($document?->web_template_data['merged_html'] ?? '');
        }
        if (trim($merged) === '') {
            return '';
        }

        $ref = trim((string) $amendment->flag_clause_ref);
        $note = trim((string) ($amendment->flag_reason ?? $amendment->new_text ?? ''));
        $signer = $amendment->amendedByRequest?->signer_name ?: 'The signing party';

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__amreview_root">' . $merged . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        if ($ref !== '') {
            foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " corex-clause ")]') as $clause) {
                if (! $clause instanceof \DOMElement) {
                    continue;
                }
                $numEl = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " corex-clause-number ")]', $clause)->item(0);
                if (! $numEl || trim($numEl->textContent) !== $ref) {
                    continue;
                }
                $clause->setAttribute('class', trim($clause->getAttribute('class') . ' amendment-flagged'));
                // Attach the recipient's note callout immediately after the clause.
                $callout = $dom->createElement('div');
                $callout->setAttribute('class', 'amendment-note');
                $head = $dom->createElement('strong');
                $head->appendChild($dom->createTextNode($signer . ' flagged this clause:'));
                $callout->appendChild($head);
                $callout->appendChild($dom->createElement('br'));
                $callout->appendChild($dom->createTextNode($note !== '' ? $note : '(no note provided)'));
                if ($clause->parentNode) {
                    $clause->parentNode->insertBefore($callout, $clause->nextSibling);
                }
                break;
            }
        }

        $root = $dom->getElementById('__amreview_root');
        if (! $root) {
            return $merged;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

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
