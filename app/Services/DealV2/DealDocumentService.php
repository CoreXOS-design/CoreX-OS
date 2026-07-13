<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-158 DR2 · WS3 — the document spine (decision D4).
 *
 * Single owner of the invariant: "one upload / split / sign, linked
 * everywhere, and — where the pipeline expects it — auto-completing the
 * matching step." Every WS3 ingress (upload-onto-deal, PDF-splitter deal
 * target, e-sign auto-file) funnels through this service so the deal↔document
 * wiring lives in exactly one place, not scattered across controllers.
 *
 * Doctrine:
 *  - A deal-anchored `Document` is reachable from the deal, its property, and
 *    its contacts (D4). Linking is idempotent (syncWithoutDetaching).
 *  - Status is only ever changed through DealPipelineService (the engine is the
 *    single writer). This service never flips a step status by hand.
 *  - Matching is CONFIG-DRIVEN, never hardcoded: a step declares which
 *    document type satisfies it via completion_config.document_type_id. No
 *    config → no auto-match → graceful manual path (never a false completion).
 *  - Guarded everywhere: a missing property, a deleted relation, 0-or-many
 *    candidate deals/steps all resolve to a safe no-op, never a 500.
 */
class DealDocumentService
{
    public function __construct(private DealPipelineService $pipelineService)
    {
    }

    /**
     * DR2 BRIDGE (AT-226 docs lane) — file a Document uploaded on the canonical DR2
     * deal view, which is the DR1-faithful twin on the `deals` table (m3's rebuild),
     * NOT deals_v2. `documents.deal_id` FKs to `deals_v2`, so we bridge via the DR1
     * deal's twin pointer (`deals.deal_v2_id`), and — because a DR1 deal has no direct
     * contacts row — we file the CONTACT pillar from the property's linked contacts
     * (the source the AT-217 capture uses). One upload → 3 pillars: deal (twin) +
     * property + property-contacts. Idempotent; every missing link is a safe skip.
     *
     * @param array{original_name:string,storage_path:string,disk?:string,mime_type?:string,size?:int,document_type_id?:int,source_type?:string,pipeline_step_id?:int} $attrs
     */
    public function fileDealDocumentFromDeal(Deal $dr1Deal, array $attrs, User $uploader): Document
    {
        return DB::transaction(function () use ($dr1Deal, $attrs, $uploader) {
            $doc = Document::create([
                'original_name'    => $attrs['original_name'],
                'storage_path'     => $attrs['storage_path'],
                'disk'             => $attrs['disk'] ?? config('filesystems.default', 'local'),
                'mime_type'        => $attrs['mime_type'] ?? null,
                'size'             => $attrs['size'] ?? 0,
                'document_type_id' => $attrs['document_type_id'] ?? null,
                'source_type'      => $attrs['source_type'] ?? 'deal', // reachable from the DR1 deal
                'source_id'        => $dr1Deal->id,
                'deal_id'          => $dr1Deal->deal_v2_id ?: null,    // deals_v2 anchor (null pre-twin)
                'agency_id'        => $dr1Deal->agency_id,             // explicit — NOT-NULL, no Auth in queue/console
                'branch_id'        => $dr1Deal->branch_id,
                'uploaded_by'      => $uploader->id,
            ]);

            // Property pillar — the deal's property.
            if ($dr1Deal->property_id) {
                $doc->properties()->syncWithoutDetaching([$dr1Deal->property_id]);
            }

            // Contact pillar — the property's linked contacts (owner/seller).
            $property = $dr1Deal->property;
            if ($property) {
                $contactIds = $property->contacts()->pluck('contacts.id')->all();
                if (!empty($contactIds)) {
                    $doc->contacts()->syncWithoutDetaching($contactIds);
                }
            }

            // Per-step attach (optional) — record on deal_step_documents when a step
            // is supplied, so the doc lands on its pipeline step (gas CoC → gas step).
            if (!empty($attrs['pipeline_step_id'])) {
                $this->attachToStepInstance($doc, (int) $attrs['pipeline_step_id']);
            }

            return $doc;
        });
    }

    /**
     * Idempotently link a document to a pipeline step instance (deal_step_documents).
     * agency_id is NOT NULL with no default and a raw insert gets no BelongsToAgency
     * stamp — supply it from the document (AT-203 landmine class). No updated_at column.
     */
    private function attachToStepInstance(Document $doc, int $stepInstanceId): void
    {
        try {
            DB::table('deal_step_documents')->updateOrInsert(
                ['deal_step_instance_id' => $stepInstanceId, 'document_id' => $doc->id],
                [
                    'agency_id'      => $doc->agency_id,
                    'file_name'      => $doc->original_name,
                    'uploaded_by_id' => $doc->uploaded_by,
                    'created_at'     => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('fileDealDocumentFromDeal: step link skipped', ['step' => $stepInstanceId, 'e' => $e->getMessage()]);
        }
    }

    /**
     * Create a unified Document that belongs to $deal and link it to the deal's
     * property + contacts in one pass. agency_id is stamped by BelongsToAgency.
     */
    public function createDealDocument(DealV2 $deal, array $attrs, User $uploader): Document
    {
        return DB::transaction(function () use ($deal, $attrs, $uploader) {
            $doc = Document::create([
                'original_name'    => $attrs['original_name'],
                'storage_path'     => $attrs['storage_path'],
                'disk'             => $attrs['disk'] ?? config('filesystems.default', 'local'),
                'mime_type'        => $attrs['mime_type'] ?? null,
                'size'             => $attrs['size'] ?? 0,
                'document_type_id' => $attrs['document_type_id'] ?? null,
                'source_type'      => $attrs['source_type'] ?? 'deal_upload',
                'source_id'        => $deal->id,
                'branch_id'        => $deal->branch_id,
                'uploaded_by'      => $uploader->id,
                'deal_id'          => $deal->id,
            ]);

            $this->linkDocumentToDeal($doc, $deal);

            return $doc;
        });
    }

    /**
     * Anchor an existing Document to a deal and mirror the deal's property +
     * contacts onto its pivots. Idempotent — safe to call repeatedly.
     */
    public function linkDocumentToDeal(Document $doc, DealV2 $deal): void
    {
        DB::transaction(function () use ($doc, $deal) {
            if ((int) $doc->deal_id !== (int) $deal->id) {
                $doc->deal_id = $deal->id;
                $doc->save();
            }

            // Reachable from the property (D4).
            if ($deal->property_id) {
                $doc->properties()->syncWithoutDetaching([$deal->property_id]);
            }

            // Reachable from each real contact party. Provider-only parties
            // (contact_id NULL) never surface in $deal->contacts, so they are
            // naturally excluded — a directory provider is not a document party.
            foreach ($deal->contacts as $contact) {
                $role = $contact->pivot->role ?? null;
                $doc->contacts()->syncWithoutDetaching([
                    $contact->id => ['party_role' => $role],
                ]);
            }
        });
    }

    /**
     * Resolve the single ACTIVE deal for a property, or null. Deliberately
     * refuses to guess: 0 or >1 active deals → null (the caller then simply
     * files the document without a deal anchor — no silent mis-link).
     */
    public function resolveDealForProperty(?int $propertyId, ?int $agencyId): ?DealV2
    {
        if (! $propertyId || ! $agencyId) {
            return null;
        }

        $deals = DealV2::where('property_id', $propertyId)
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->get();

        return $deals->count() === 1 ? $deals->first() : null;
    }

    /**
     * Link $doc to the matching document-bearing step and — if that step is
     * currently ACTIVE — complete it through the engine. Returns the resolved
     * step (linked, possibly completed) or null when nothing matched.
     *
     * $preferStep lets a human explicitly target a step (upload-onto-deal with
     * a chosen step); otherwise the match is config-driven by document type.
     *
     * Idempotent: a re-fire finds the document already linked / the step
     * already completed and no-ops.
     */
    public function autoCompleteMatchingStep(
        DealV2 $deal,
        Document $doc,
        User $actor,
        ?DealStepInstance $preferStep = null
    ): ?DealStepInstance {
        $step = $preferStep ?: $this->findMatchingStep($deal, $doc);

        if (! $step) {
            return null;
        }

        // Only document steps can be satisfied by a document.
        if (! in_array($step->completion_type, ['document_upload', 'document_signed'], true)) {
            return null;
        }

        // Belongs-to-deal guard (a hand-supplied preferStep might not).
        if ((int) $step->deal_id !== (int) $deal->id) {
            return null;
        }

        $alreadyLinked = $step->documents()->where('document_id', $doc->id)->exists();

        // Active + not yet linked → complete through the engine (single writer
        // of status). completeStep records the DealStepDocument with the
        // document_id itself, so we do NOT pre-create the link row here.
        if ($step->status === 'active' && ! $alreadyLinked) {
            $this->pipelineService->completeStep($step, $actor, [
                'outcome'     => 'positive',
                'document_id' => $doc->id,
                'file_path'   => $doc->storage_path,
                'file_name'   => $doc->original_name,
                'notes'       => 'Auto-completed on filing of "' . ($doc->original_name ?? 'document') . '".',
            ]);

            return $step->fresh();
        }

        // Not-active (not_started / completed / skipped) OR already linked:
        // record the document against the step for provenance, never forcing a
        // status change on an inactive step.
        if (! $alreadyLinked) {
            $step->documents()->create([
                'document_id'    => $doc->id,
                'file_path'      => $doc->storage_path,
                'file_name'      => $doc->original_name,
                'uploaded_by_id' => $actor->id,
            ]);
        }

        return $step;
    }

    /**
     * End-to-end helper for the e-sign auto-file path: resolve the deal from the
     * signed document's property, link it, and auto-complete the matching
     * document_signed / document_upload step. Fully guarded — a failure here
     * must NEVER disturb a legally-completed signing.
     */
    public function attachSignedDocumentToDeal(Document $doc, ?int $propertyId, ?User $actor): ?DealV2
    {
        try {
            $deal = $this->resolveDealForProperty($propertyId, $doc->agency_id ? (int) $doc->agency_id : null);
            if (! $deal) {
                return null;
            }

            $this->linkDocumentToDeal($doc, $deal);

            if ($actor) {
                $this->autoCompleteMatchingStep($deal, $doc, $actor);
            }

            return $deal;
        } catch (\Throwable $e) {
            Log::warning('DealDocumentService: signed-document deal link failed (non-fatal)', [
                'document_id' => $doc->id ?? null,
                'property_id' => $propertyId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find the single ACTIVE document-bearing step on the deal whose configured
     * expected document type matches the filed document. Exactly-one-or-null.
     */
    protected function findMatchingStep(DealV2 $deal, Document $doc): ?DealStepInstance
    {
        if (! $doc->document_type_id) {
            return null;
        }

        $candidates = $deal->stepInstances()
            ->whereIn('completion_type', ['document_upload', 'document_signed'])
            ->where('status', 'active')
            ->get()
            ->filter(function (DealStepInstance $s) use ($doc) {
                $expected = data_get($s->completion_config, 'document_type_id');

                return $expected !== null && (int) $expected === (int) $doc->document_type_id;
            });

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
