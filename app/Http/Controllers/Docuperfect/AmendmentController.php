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

        return redirect()->back()->with('status', 'Document rejected. All parties notified. Terminal state.');
    }
}
