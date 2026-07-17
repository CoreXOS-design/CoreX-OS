<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-231 P2 — the write side of correspondence filing. Parks a known-attorney
 * inbound email, resolves it (CorrespondenceMatchService), and either auto-files
 * it (a verified learned-ref) or raises a suspense row for the agent's first
 * verify. Also owns verify, reassign (edit a wrong link), and the learned-ref
 * write. Filing routes through DealDocumentService (the one filing truth).
 *
 * See .ai/specs/at231-inbound-attorney-comms-filing.md §§3.4–3.8.
 */
class CorrespondenceFilingService
{
    public function __construct(
        private AttorneyCorrespondenceResolver $resolver,
        private CorrespondenceMatchService $matcher,
        private DealDocumentService $docs,
        private CommunicationStorageService $storage,
    ) {
    }

    /** Passthrough gate — is this sender a known attorney firm (→ park, not drop)? */
    public function resolveSender(string $email, int $agencyId): ?array
    {
        return $this->resolver->resolveSender($email, $agencyId);
    }

    /**
     * Park a freshly-stored attorney communication: link the attorney, resolve to
     * a deal, then auto-file (learned) or raise a suspense row. Returns 'filed'
     * (silent auto) or 'suspended'.
     *
     * @param array $attorney {provider, contact}
     */
    public function park(Communication $comm, array $msg, array $attorney): string
    {
        $agencyId = (int) $comm->agency_id;
        $provider = $attorney['provider'] ?? null;
        $contact  = $attorney['contact'] ?? null;

        // Provenance: link the attorney firm (+ person) to the parked comm.
        $this->linkModel($comm, $provider, CommunicationLink::METHOD_DETERMINISTIC, 100);
        if ($contact) {
            $this->linkModel($comm, $contact, CommunicationLink::METHOD_DETERMINISTIC, 100);
        }

        $match = $this->matcher->resolve($agencyId, $msg, $attorney);

        // Verified learned-ref → file silently, no suspense. Degrade to suspense if
        // we cannot resolve an uploader (never break the ingest).
        if ($match['tier'] === CorrespondenceMatchService::TIER_AUTO && $match['deal_id']) {
            $deal = $this->findDeal((int) $match['deal_id'], $agencyId);
            $uploader = $deal ? $this->resolveUploader(null, $comm, $deal) : null;
            if ($deal && $uploader) {
                $this->fileToDeal($comm, $deal, $uploader, $match['signal_type'], $match['signal_value']);
                $this->bumpLearnedHits($agencyId, $provider?->id, $match['signal_type'], $match['signal_value']);
                $this->audit('auto_filed', $comm, (int) $deal->id, $match);
                return 'filed';
            }
        }

        // Otherwise raise a suspense row for first-verify / manual link.
        $suspense = CommunicationFilingSuspense::create([
            'agency_id'                    => $agencyId,
            'communication_id'             => $comm->id,
            'channel'                      => $comm->channel,
            'suggested_deal_id'            => $match['deal_id'],
            'confidence'                   => $this->confidenceFor($match['tier']),
            'status'                       => CommunicationFilingSuspense::STATUS_PENDING,
            'matched_signal_type'          => $match['signal_type'],
            'matched_signal_value'         => $match['signal_value'],
            'attorney_provider_id'         => $provider?->id,
            'attorney_provider_contact_id' => $contact?->id,
        ]);

        // A suggested deal gets a PROVISIONAL (unconfirmed) deal link so it already
        // shows on the deal, pending the agent's verify.
        if ($match['deal_id'] && ($deal = $this->findDeal((int) $match['deal_id'], $agencyId))) {
            $this->linkDealPillars($comm, $deal, confirmed: false);
        }

        $this->audit('suspended', $comm, $match['deal_id'] ? (int) $match['deal_id'] : null, $match);

        return 'suspended';
    }

    /**
     * First-verify (or manual link): file the correspondence to $dealId, learn the
     * signal so future same-ref mail auto-files, and close the suspense.
     *
     * @throws \DomainException on a missing/deleted deal
     */
    public function verify(CommunicationFilingSuspense $suspense, int $dealId, User $actor): void
    {
        $agencyId = (int) $suspense->agency_id;
        $deal = $this->findDeal($dealId, $agencyId);
        if (! $deal) {
            throw new \DomainException('That deal no longer exists — pick another to file this correspondence.');
        }

        $comm = Communication::withoutGlobalScopes()->findOrFail($suspense->communication_id);

        DB::transaction(function () use ($suspense, $deal, $comm, $actor, $agencyId) {
            // Confirm any provisional links + file the attachments.
            $signalType  = $suspense->matched_signal_type;
            $signalValue = $suspense->matched_signal_value;
            $this->fileToDeal($comm, $deal, $actor, $signalType, $signalValue);

            // Learn the signal (so the rest of the transaction is silent).
            $this->learn($agencyId, (int) $deal->id, $suspense->attorney_provider_id, $suspense->attorney_provider_contact_id, $signalType, $signalValue, $actor);

            $suspense->update([
                'status'              => CommunicationFilingSuspense::STATUS_VERIFIED,
                'resolved_deal_id'    => $deal->id,
                'resolved_by_user_id' => $actor->id,
                'resolved_at'         => now(),
            ]);

            $this->audit('verified', $comm, (int) $deal->id, [
                'signal_type' => $signalType, 'signal_value' => $signalValue, 'by' => $actor->id,
            ]);
        });
    }

    /**
     * Edit / reassign (§3.8): move a wrongly-filed correspondence to the correct
     * deal. Withdraws the old documents + links cleanly (soft, no orphan), re-files
     * to the new deal, and CORRECTS the learned pattern so future mail follows the
     * fix. Idempotent; reassign-to-same is a no-op; reassign-to-deleted is refused.
     *
     * @throws \DomainException on a missing/deleted target deal
     */
    public function reassign(Communication $comm, int $newDealId, User $actor, ?string $reason = null): void
    {
        $agencyId = (int) $comm->agency_id;
        $newDeal = $this->findDeal($newDealId, $agencyId);
        if (! $newDeal) {
            throw new \DomainException('That deal no longer exists — pick another.');
        }

        $currentDealId = $this->currentDealId($comm);
        if ($currentDealId === (int) $newDeal->id) {
            return; // no-op
        }

        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('communication_id', $comm->id)->first();

        DB::transaction(function () use ($comm, $newDeal, $actor, $agencyId, $currentDealId, $suspense) {
            // 1) Withdraw the old filing — soft-delete the documents this email produced
            //    and its old deal/property links (no hard delete, no orphan).
            $this->withdrawFiling($comm);

            // 2) Re-file to the corrected deal.
            $uploader = $this->resolveUploader($actor, $comm, $newDeal);
            $signalType  = $suspense?->matched_signal_type;
            $signalValue = $suspense?->matched_signal_value;
            $this->fileToDeal($comm, $newDeal, $uploader, $signalType, $signalValue);

            // 3) Correct the learned pattern — re-point the mis-learned signal to the
            //    corrected deal so future same-ref mail follows the fix.
            if ($signalType && $signalValue !== null && $signalValue !== '') {
                $this->correctLearned($agencyId, $signalType, $signalValue, (int) $newDeal->id, $actor);
            }

            if ($suspense) {
                $suspense->update([
                    'status'              => CommunicationFilingSuspense::STATUS_VERIFIED,
                    'resolved_deal_id'    => $newDeal->id,
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at'         => now(),
                ]);
            }

            $this->audit('reassigned', $comm, (int) $newDeal->id, [
                'from_deal' => $currentDealId, 'to_deal' => (int) $newDeal->id, 'by' => $actor->id,
            ]);
        });
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** Create the Documents (3-pillar via DealDocumentService) + comm↔deal/doc links. */
    private function fileToDeal(Communication $comm, Deal $deal, User $uploader, ?string $signalType, ?string $signalValue): void
    {
        $atts = CommunicationAttachment::withoutGlobalScopes()
            ->where('communication_id', $comm->id)->whereNull('deleted_at')->get();

        foreach ($atts as $att) {
            $bytes = $this->storage->get((string) $att->storage_path);
            if ($bytes === null) {
                continue; // absorb a missing blob — never break the file
            }
            $disk = 'local';
            $path = 'deals/' . $deal->id . '/correspondence/' . Str::random(12) . '_' . $this->safeName($att->filename);
            Storage::disk($disk)->put($path, $bytes);

            $doc = $this->docs->fileDealDocumentFromDeal($deal, [
                'original_name' => $att->filename ?: 'attachment.pdf',
                'storage_path'  => $path,
                'disk'          => $disk,
                'mime_type'     => $att->mime ?: 'application/octet-stream',
                'size'          => (int) $att->size_bytes,
                'source_type'   => 'inbound_email',
            ], $uploader);

            // Provenance link: this email produced this document (drives reassign withdrawal).
            $this->linkModel($comm, $doc, CommunicationLink::METHOD_ATTORNEY_REF, 100, confirmed: true);
        }

        // Confirm the deal + property pillar links.
        $this->linkDealPillars($comm, $deal, confirmed: true);
    }

    /** Soft-withdraw everything this email filed (documents + old deal/property/doc links). */
    private function withdrawFiling(Communication $comm): void
    {
        $links = CommunicationLink::withoutGlobalScopes()
            ->where('communication_id', $comm->id)->whereNull('deleted_at')->get();

        $docMorph  = (new Document())->getMorphClass();
        $dealMorph = (new DealV2())->getMorphClass();
        $propMorph = (new \App\Models\Property())->getMorphClass();

        foreach ($links as $link) {
            if ($link->linkable_type === $docMorph) {
                $doc = Document::withoutGlobalScopes()->find($link->linkable_id);
                $doc?->delete(); // soft — recoverable, no orphan
                $link->delete();
            } elseif (in_array($link->linkable_type, [$dealMorph, $propMorph], true)) {
                $link->delete();
            }
            // Attorney firm/person links stay — the email is still theirs.
        }
    }

    private function linkDealPillars(Communication $comm, Deal $deal, bool $confirmed): void
    {
        $twin = $deal->deal_v2_id ? DealV2::withoutGlobalScopes()->find($deal->deal_v2_id) : null;
        if ($twin) {
            $this->linkModel($comm, $twin, CommunicationLink::METHOD_ATTORNEY_REF, 100, $confirmed);
        }
        if ($deal->property_id && ($prop = $deal->property)) {
            $this->linkModel($comm, $prop, CommunicationLink::METHOD_ATTORNEY_REF, 100, $confirmed);
        }
    }

    private function linkModel(Communication $comm, $model, string $method, int $confidence, bool $confirmed = true): void
    {
        if (! $model) {
            return;
        }
        CommunicationLink::updateOrCreate(
            [
                'communication_id' => $comm->id,
                'linkable_type'    => $model->getMorphClass(),
                'linkable_id'      => $model->getKey(),
            ],
            [
                'agency_id'    => (int) $comm->agency_id,
                'link_method'  => $method,
                'confidence'   => $confidence,
                'confirmed_at' => $confirmed ? now() : null,
            ]
        );
    }

    private function learn(int $agencyId, int $dealId, ?int $providerId, ?int $contactId, ?string $signalType, ?string $signalValue, User $actor): void
    {
        if (! $signalType || $signalValue === null || $signalValue === '') {
            return; // LOW resolves with no learnable signal (agent linked blind) — nothing to learn
        }
        try {
            CommunicationLearnedRef::updateOrCreate(
                [
                    'agency_id'    => $agencyId,
                    'signal_type'  => $signalType,
                    'signal_value' => CommunicationLearnedRef::normalizeValue($signalValue),
                ],
                [
                    'deal_id'                      => $dealId,
                    'attorney_provider_id'         => $providerId,
                    'attorney_provider_contact_id' => $contactId,
                    'is_verified'                  => true,
                    'verified_by_user_id'          => $actor->id,
                    'verified_at'                  => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Learning must never break the file (failure-isolated, like pdf_splitter).
            Log::warning('AT-231 learn-ref failed: ' . $e->getMessage(), ['deal_id' => $dealId]);
        }
    }

    private function correctLearned(int $agencyId, string $signalType, string $signalValue, int $newDealId, User $actor): void
    {
        try {
            CommunicationLearnedRef::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('signal_type', $signalType)
                ->where('signal_value', CommunicationLearnedRef::normalizeValue($signalValue))
                ->update([
                    'deal_id'             => $newDealId,
                    'is_verified'         => true,
                    'verified_by_user_id' => $actor->id,
                    'verified_at'         => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('AT-231 correct-learned failed: ' . $e->getMessage(), ['deal_id' => $newDealId]);
        }
    }

    private function bumpLearnedHits(int $agencyId, ?int $providerId, ?string $signalType, ?string $signalValue): void
    {
        if (! $signalType || $signalValue === null) {
            return;
        }
        CommunicationLearnedRef::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('signal_type', $signalType)
            ->where('signal_value', CommunicationLearnedRef::normalizeValue($signalValue))
            ->increment('hits');
    }

    private function currentDealId(Communication $comm): ?int
    {
        $dealMorph = (new DealV2())->getMorphClass();
        $twinId = CommunicationLink::withoutGlobalScopes()
            ->where('communication_id', $comm->id)
            ->where('linkable_type', $dealMorph)
            ->whereNull('deleted_at')
            ->value('linkable_id');
        if (! $twinId) {
            return null;
        }
        $id = Deal::withoutGlobalScopes()->whereNull('deleted_at')->where('deal_v2_id', $twinId)->value('id');

        return $id ? (int) $id : null;
    }

    private function findDeal(int $dealId, int $agencyId): ?Deal
    {
        return Deal::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $agencyId)->where('id', $dealId)->first();
    }

    /** Resolve a User to attribute the file to: explicit actor → comm owner → deal's twin agent. */
    private function resolveUploader(?User $actor, Communication $comm, Deal $deal): ?User
    {
        if ($actor) {
            return $actor;
        }
        if ($comm->owner_user_id && ($u = User::find($comm->owner_user_id))) {
            return $u;
        }
        if ($deal->deal_v2_id) {
            $agentId = DealV2::withoutGlobalScopes()->where('id', $deal->deal_v2_id)->value('listing_agent_id');
            if ($agentId && ($u = User::find($agentId))) {
                return $u;
            }
        }

        return null;
    }

    private function confidenceFor(string $tier): string
    {
        return match ($tier) {
            CorrespondenceMatchService::TIER_HIGH,
            CorrespondenceMatchService::TIER_AUTO   => CommunicationFilingSuspense::CONF_HIGH,
            CorrespondenceMatchService::TIER_MEDIUM => CommunicationFilingSuspense::CONF_MEDIUM,
            default                                 => CommunicationFilingSuspense::CONF_LOW,
        };
    }

    private function safeName(?string $name): string
    {
        $name = $name ?: 'attachment';
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
    }

    private function audit(string $event, Communication $comm, ?int $dealId, array $ctx): void
    {
        Log::info('AT-231 correspondence ' . $event, array_merge([
            'agency_id'        => (int) $comm->agency_id,
            'communication_id' => $comm->id,
            'deal_id'          => $dealId,
            'at'               => now()->toIso8601String(),
        ], $ctx));
    }
}
