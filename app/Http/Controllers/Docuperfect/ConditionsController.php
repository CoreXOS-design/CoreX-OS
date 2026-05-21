<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * E-Sign V3 (ES-9) — POST endpoints for adding conditions and proposing
 * strikethrough overrides during signing or agent preparation.
 *
 * Routes (registered in routes/web.php):
 *   POST /docuperfect/signing/{signatureTemplate}/conditions
 *   POST /docuperfect/signing/{signatureTemplate}/strikethroughs
 *
 * Both endpoints trigger amendment_status = 'pending_review' and (where the
 * signing flow demands it) flip the parent template status to
 * STATUS_AMENDMENT_REVIEW so the wizard halts forward progress until the
 * agent reviews + approves / rejects.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.4, §7.5.5, §10
 */
class ConditionsController extends Controller
{
    /**
     * POST /docuperfect/signing/{signatureTemplate}/conditions
     *
     * Add a new condition to one of the insertable blocks on the document.
     * For 'other_conditions' blocks the content is ALSO appended to
     * signature_templates.other_conditions_text (the canonical store for
     * that block per spec §7.5.10).
     */
    public function storeCondition(Request $request, SignatureTemplate $signatureTemplate): JsonResponse
    {
        $validated = $request->validate([
            'block_id'             => ['required', 'string', 'max:100'],
            'block_purpose'        => ['required', 'in:other_conditions,included_items,excluded_items,custom_named'],
            'custom_label'         => ['nullable', 'string', 'max:255'],
            'content'              => ['required', 'string', 'max:4000'],
            'source'               => ['required', 'in:library,custom'],
            'library_clause_id'    => ['nullable', 'integer'],
            'added_via'            => ['required', 'in:agent_preparation,agent_signing,recipient_signing,system_default'],
            'added_by_party_id'    => ['nullable', 'integer'],
            'is_locked'            => ['sometimes', 'boolean'],
        ]);

        $user      = $request->user();
        $agencyId  = $user?->effectiveAgencyId();

        $condition = DB::transaction(function () use ($validated, $signatureTemplate, $user, $agencyId) {
            // Resolve next condition_number within this block
            $next = (int) DocumentCondition::where('signature_template_id', $signatureTemplate->id)
                ->where('block_id', $validated['block_id'])
                ->whereNull('superseded_at')
                ->max('condition_number');
            $conditionNumber = $next + 1;

            // Open or reuse the in-flight amendment for this template
            $amendment = $this->openPendingAmendment(
                $signatureTemplate,
                amendmentType: DocumentAmendment::TYPE_ADDITION,
                originalText: '',
                newText: $validated['content']
            );

            $condition = DocumentCondition::create([
                'signature_template_id' => $signatureTemplate->id,
                'agency_id'             => $agencyId,
                'block_id'              => $validated['block_id'],
                'block_purpose'         => $validated['block_purpose'],
                'custom_label'          => $validated['custom_label'] ?? null,
                'condition_number'      => $conditionNumber,
                'content'               => $validated['content'],
                'is_locked'             => $validated['is_locked'] ?? false,
                'is_override'           => false,
                'added_by_user_id'      => $user?->id,
                'added_by_party_id'     => $validated['added_by_party_id'] ?? null,
                'added_via'             => $validated['added_via'],
                'source'                => $validated['source'],
                'library_clause_id'     => $validated['library_clause_id'] ?? null,
                'amendment_id'          => $amendment->id,
            ]);

            // For 'other_conditions' blocks also append to the free-form
            // longText column on signature_templates (the canonical store
            // for that block per spec §7.5.10).
            if ($validated['block_purpose'] === 'other_conditions') {
                $this->appendOtherConditionsText($signatureTemplate, $conditionNumber, $validated['content']);
            }

            // Flip template into review state — the wizard halts here.
            $signatureTemplate->update([
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
            ]);

            return $condition;
        });

        return response()->json([
            'ok'        => true,
            'condition' => $condition,
        ], 201);
    }

    /**
     * POST /docuperfect/signing/{signatureTemplate}/strikethroughs
     *
     * Propose a strikethrough override on a printed clause. Auto-creates a
     * paired DocumentCondition in the other_conditions block.
     */
    public function storeStrikethrough(Request $request, SignatureTemplate $signatureTemplate): JsonResponse
    {
        $validated = $request->validate([
            'clause_ref'             => ['required', 'string', 'max:50'],
            'clause_original_text'   => ['required', 'string', 'max:4000'],
            'replacement_content'    => ['required', 'string', 'max:4000'],
            'proposed_by_party_id'   => ['nullable', 'integer'],
            'library_clause_id'      => ['nullable', 'integer'],
        ]);

        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();

        $payload = DB::transaction(function () use ($validated, $signatureTemplate, $user, $agencyId) {
            $amendment = $this->openPendingAmendment(
                $signatureTemplate,
                amendmentType: DocumentAmendment::TYPE_STRIKEOUT,
                originalText: $validated['clause_original_text'],
                newText: $validated['replacement_content']
            );

            // The paired condition that lands in the other_conditions block.
            // We auto-create it with a reference back to the struck-through
            // clause ref.
            $next = (int) DocumentCondition::where('signature_template_id', $signatureTemplate->id)
                ->where('block_id', 'other_conditions')
                ->whereNull('superseded_at')
                ->max('condition_number');
            $conditionNumber = $next + 1;

            $referencedContent = sprintf(
                'Override of clause %s: %s',
                $validated['clause_ref'],
                $validated['replacement_content']
            );

            $condition = DocumentCondition::create([
                'signature_template_id' => $signatureTemplate->id,
                'agency_id'             => $agencyId,
                'block_id'              => 'other_conditions',
                'block_purpose'         => 'other_conditions',
                'condition_number'      => $conditionNumber,
                'content'               => $referencedContent,
                'is_locked'             => false,
                'is_override'           => true,
                'overrides_clause_ref'  => $validated['clause_ref'],
                'added_by_user_id'      => $user?->id,
                'added_by_party_id'     => $validated['proposed_by_party_id'] ?? null,
                'added_via'             => 'recipient_signing',
                'source'                => isset($validated['library_clause_id']) ? 'library' : 'custom',
                'library_clause_id'     => $validated['library_clause_id'] ?? null,
                'amendment_id'          => $amendment->id,
            ]);

            $strikethrough = DocumentClauseStrikethrough::create([
                'signature_template_id'     => $signatureTemplate->id,
                'agency_id'                 => $agencyId,
                'clause_ref'                => $validated['clause_ref'],
                'clause_original_text'      => $validated['clause_original_text'],
                'replacement_condition_id'  => $condition->id,
                'proposed_by_user_id'       => $user?->id,
                'proposed_by_party_id'      => $validated['proposed_by_party_id'] ?? null,
                'amendment_id'              => $amendment->id,
                'status'                    => DocumentClauseStrikethrough::STATUS_PROPOSED,
            ]);

            $this->appendOtherConditionsText($signatureTemplate, $conditionNumber, $referencedContent);

            $signatureTemplate->update([
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
            ]);

            return compact('condition', 'strikethrough', 'amendment');
        });

        return response()->json([
            'ok'            => true,
            'strikethrough' => $payload['strikethrough'],
            'condition'     => $payload['condition'],
            'amendment_id'  => $payload['amendment']->id,
        ], 201);
    }

    /**
     * Reuse the active pending-review amendment for this template if one
     * exists, otherwise open a fresh one. Multiple conditions added in the
     * same review window share one amendment row.
     */
    private function openPendingAmendment(
        SignatureTemplate $signatureTemplate,
        string $amendmentType,
        string $originalText,
        string $newText
    ): DocumentAmendment {
        $existing = DocumentAmendment::where('signature_template_id', $signatureTemplate->id)
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        $currentVersion = (int) ($signatureTemplate->document_version ?? 1);

        return DocumentAmendment::create([
            'document_id'              => $signatureTemplate->document_id,
            'signature_template_id'    => $signatureTemplate->id,
            'amended_by_request_id'    => null,
            'amendment_type'           => $amendmentType,
            'section_reference'        => 'Other Conditions',
            'original_text'            => $originalText,
            'new_text'                 => $newText,
            'document_version_before'  => $currentVersion,
            'document_version_after'   => $currentVersion + 1,
            'document_hash_before'     => $signatureTemplate->document_hash,
            'document_hash_after'      => null,
            'status'                   => DocumentAmendment::STATUS_PENDING,
        ]);
    }

    /**
     * Append a numbered line to signature_templates.other_conditions_text.
     * Keeps the canonical free-form block in sync with row-per-condition
     * additions for the 'other_conditions' purpose.
     */
    private function appendOtherConditionsText(
        SignatureTemplate $signatureTemplate,
        int $number,
        string $content
    ): void {
        $existing = trim((string) $signatureTemplate->other_conditions_text);
        $newLine  = sprintf('%d. %s', $number, $content);
        $combined = $existing === '' ? $newLine : $existing . "\n" . $newLine;

        $signatureTemplate->update([
            'other_conditions_text' => $combined,
        ]);
    }
}
