<?php

namespace App\Services\DealV2;

use App\Mail\DealV2\DealDocumentDeliveryMail;
use App\Mail\DealV2\DealSecureLinkMail;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealDocumentAccessLog;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-158 DR2 · WS4 (§8, §10) — the distribution brain.
 *
 * Resolves the matrix (stage × doc-type × party-role) against a deal's parties,
 * providers and documents; sends each document to each party by the configured
 * mode (secure_link + OTP by default, or direct_attachment); records a
 * DealDocumentDistribution + a 3-pillar archived Communication; and — the
 * red-button moment — auto-fires on a stage tick, generating the COC request
 * from deal/property/contact data when the rule needs one.
 *
 * Everything is guarded (prevent-or-absorb, §16): a rule whose party the deal
 * doesn't have, or whose document can't be resolved, is SKIPPED with a note —
 * never a crash, never a silent drop.
 */
class DealDistributionService
{
    public function __construct(
        private OutboundProvisionalLogger $provisionalLogger,
        private CocRequestGenerator $cocGenerator,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────
    // Auto-distribution on a stage tick (§8.3)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fire every auto_on_stage_tick rule for the completed step's stage. Returns
     * the distributions created. Guarded end-to-end.
     *
     * @return DealDocumentDistribution[]
     */
    public function autoDistributeForStep(DealStepInstance $stepInstance, User $actor): array
    {
        $deal = $stepInstance->deal;
        if (! $deal || $deal->status !== 'active') {
            return [];
        }

        $rules = DealStageDocumentRule::query()
            ->where('agency_id', $deal->agency_id)
            ->where('pipeline_step_id', $stepInstance->pipeline_step_id)
            ->where('auto_on_stage_tick', true)
            ->active()
            ->get();

        $created = [];
        foreach ($rules as $rule) {
            try {
                foreach ($this->sendRule($deal, $rule, $actor) as $dist) {
                    $created[] = $dist;
                }
            } catch (\Throwable $e) {
                Log::warning('DealDistribution: auto rule failed (non-fatal)', [
                    'deal_id' => $deal->id, 'rule_id' => $rule->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    // ──────────────────────────────────────────────────────────────────
    // Manual "Distribute documents" — the modal plan + confirmed send
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the manual-distribute plan for the deal's current stage(s): for each
     * applicable rule, who gets which document by which mode, and whether the
     * document is on-hand or will be generated. Rows with no recipient / no
     * resolvable document are flagged (skipped) rather than dropped.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolvePlan(DealV2 $deal, User $actor): array
    {
        $activeStepIds = $deal->stepInstances()
            ->where('status', 'active')->pluck('pipeline_step_id')->filter()->all();

        $rules = DealStageDocumentRule::query()
            ->where('agency_id', $deal->agency_id)
            ->where(function ($q) use ($activeStepIds) {
                $q->whereNull('pipeline_step_id');
                if ($activeStepIds) {
                    $q->orWhereIn('pipeline_step_id', $activeStepIds);
                }
            })
            ->active()
            ->with('documentType')
            ->get();

        $plan = [];
        foreach ($rules as $rule) {
            $recipients = $this->recipientsForRole($deal, $rule->party_role);
            $doc = $this->existingDocumentForType($deal, $rule->document_type_id);
            $canGenerate = ! $doc && $this->isCocType($rule->document_type_id);

            $plan[] = [
                'rule_id'        => $rule->id,
                'document_type'  => $rule->documentType->label ?? 'Document',
                'party_role'     => $rule->party_role,
                'party_label'    => Str::headline($rule->party_role),
                'delivery_mode'  => $rule->delivery_mode,
                'recipients'     => array_map(fn ($r) => [
                    'name'  => $r['name'],
                    'email' => $r['email'],
                    'type'  => $r['type'],
                ], $recipients),
                'has_document'   => (bool) $doc,
                'will_generate'  => $canGenerate,
                'sendable'       => ! empty($recipients) && ($doc || $canGenerate),
                'skip_reason'    => $this->skipReason($recipients, $doc, $canGenerate),
            ];
        }

        return $plan;
    }

    /** Execute the confirmed manual plan for a set of rule ids. */
    public function distributeRules(DealV2 $deal, array $ruleIds, User $actor): array
    {
        $rules = DealStageDocumentRule::query()
            ->where('agency_id', $deal->agency_id)
            ->whereIn('id', $ruleIds)
            ->active()
            ->get();

        $created = [];
        foreach ($rules as $rule) {
            foreach ($this->sendRule($deal, $rule, $actor) as $dist) {
                $created[] = $dist;
            }
        }
        return $created;
    }

    // ──────────────────────────────────────────────────────────────────
    // Core: send one rule → (document × each recipient)
    // ──────────────────────────────────────────────────────────────────

    /** @return DealDocumentDistribution[] */
    private function sendRule(DealV2 $deal, DealStageDocumentRule $rule, User $actor): array
    {
        $recipients = $this->recipientsForRole($deal, $rule->party_role);
        if (empty($recipients)) {
            return []; // prevent-or-absorb: party not on the deal → skip silently (logged upstream)
        }

        $out = [];
        foreach ($recipients as $recipient) {
            $provider = $recipient['type'] === 'provider' ? $recipient['model'] : null;

            $doc = $this->existingDocumentForType($deal, $rule->document_type_id)
                ?? $this->generateIfCoc($deal, $rule, $provider, $actor);

            if (! $doc) {
                continue; // no document to send and not generatable → skip
            }

            $out[] = $this->send($deal, $doc, $rule->party_role, $rule->delivery_mode, $recipient, $actor);
        }

        return $out;
    }

    /**
     * Send a single (document → recipient) distribution. Creates the record,
     * delivers by mode, archives the outbound Communication on all three
     * pillars, and returns the distribution.
     */
    public function send(
        DealV2 $deal,
        Document $document,
        string $partyRole,
        string $mode,
        array $recipient,
        User $actor,
        bool $otpRequired = true
    ): DealDocumentDistribution {
        $isSecure = $mode === DealDocumentDistribution::MODE_SECURE_LINK;

        $dist = DealDocumentDistribution::create([
            'agency_id'             => $deal->agency_id,
            'deal_id'               => $deal->id,
            'document_id'           => $document->id,
            'party_role'            => $partyRole,
            'recipient_contact_id'  => $recipient['type'] === 'contact' ? $recipient['model']->id : null,
            'recipient_provider_id' => $recipient['type'] === 'provider' ? $recipient['model']->id : null,
            'recipient_email'       => $recipient['email'],
            'delivery_mode'         => $mode,
            'secure_token'          => $isSecure ? $this->uniqueToken() : null,
            'otp_required'          => $isSecure ? $otpRequired : false,
            'status'                => DealDocumentDistribution::STATUS_QUEUED,
            'sent_by_id'            => $actor->id,
        ]);

        $title = $document->documentType->label ?? ($document->original_name ?? 'Document');
        $propertyAddress = $deal->property->address ?? null;
        $agent = $deal->listingAgent ?? $actor;

        // Absolute path for a direct attachment.
        $absPath = null;
        if (! $isSecure) {
            $disk = Storage::disk($document->disk ?? 'local');
            if ($disk->exists($document->storage_path)) {
                $absPath = $disk->path($document->storage_path);
            }
        }

        $delivered = false;
        try {
            if ($isSecure) {
                $url = route('deals-v2.secure-doc.show', ['token' => $dist->secure_token]);
                Mail::to($recipient['email'])->send(
                    (new DealSecureLinkMail(
                        recipientName:   $recipient['name'],
                        documentTitle:   $title,
                        dealReference:   $deal->reference,
                        propertyAddress: $propertyAddress,
                        secureUrl:       $url,
                    ))->fromAgent($agent)
                );
            } else {
                Mail::to($recipient['email'])->send(
                    (new DealDocumentDeliveryMail(
                        recipientName:   $recipient['name'],
                        documentTitle:   $title,
                        dealReference:   $deal->reference,
                        propertyAddress: $propertyAddress,
                        pdfPath:         $absPath,
                        pdfFilename:     $document->original_name,
                    ))->fromAgent($agent)
                );
            }
            $delivered = true;
        } catch (\Throwable $e) {
            Log::error('DealDistribution: delivery failed', [
                'distribution_id' => $dist->id, 'error' => $e->getMessage(),
            ]);
        }

        // §10 — archive the outbound on all three pillars (deal + property +
        // contact), with the sending agent as owner and (direct mode) the
        // attachment recorded.
        $communication = $this->archiveOutbound($deal, $dist, $document, $recipient, $title, $isSecure, $absPath, $agent);

        $dist->update([
            'communication_id' => $communication?->id,
            'status'           => $delivered ? DealDocumentDistribution::STATUS_SENT : DealDocumentDistribution::STATUS_DELIVERED_FAILED,
            'sent_at'          => now(),
        ]);

        return $dist->fresh();
    }

    private function archiveOutbound(
        DealV2 $deal,
        DealDocumentDistribution $dist,
        Document $document,
        array $recipient,
        string $title,
        bool $isSecure,
        ?string $absPath,
        User $agent
    ): ?Communication {
        try {
            $links = [$deal];
            if ($deal->property) {
                $links[] = $deal->property;
            }
            if ($recipient['type'] === 'contact') {
                $links[] = $recipient['model'];
            }

            $body = $isSecure
                ? "Secure document link sent: {$title} (deal {$deal->reference})."
                : "Document sent: {$title} (deal {$deal->reference}).";

            $attachments = [];
            if (! $isSecure && $document->storage_path) {
                $attachments[] = [
                    'storage_path' => $document->storage_path,
                    'disk'         => $document->disk ?? 'local',
                    'filename'     => $document->original_name,
                    'mime'         => $document->mime_type ?? 'application/pdf',
                    'size'         => (int) $document->size,
                ];
            }

            return $this->provisionalLogger->logDistribution(
                (int) $deal->agency_id,
                $agent->id,
                $recipient['email'],
                $title,
                $body,
                $links,
                $attachments,
            );
        } catch (\Throwable $e) {
            Log::warning('DealDistribution: comms archive failed (non-fatal)', [
                'distribution_id' => $dist->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Revoke (§8.2 — links are revocable)
    // ──────────────────────────────────────────────────────────────────

    public function revoke(DealDocumentDistribution $dist, User $actor, ?string $ip = null, ?string $ua = null): void
    {
        if ($dist->isRevoked()) {
            return;
        }
        $dist->update(['status' => DealDocumentDistribution::STATUS_REVOKED]);
        DealDocumentAccessLog::record($dist, DealDocumentAccessLog::EVENT_REVOKED, [
            'by_user_id' => $actor->id,
        ], $ip, $ua);
    }

    // ──────────────────────────────────────────────────────────────────
    // Resolution helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Recipients for a party role on the deal — contacts AND directory
     * providers under that role, each with a usable email. Rows without an
     * email are dropped (can't send).
     *
     * @return array<int,array{type:string,model:mixed,name:string,email:string}>
     */
    private function recipientsForRole(DealV2 $deal, string $role): array
    {
        $out = [];

        foreach ($deal->contacts()->wherePivot('role', $role)->get() as $contact) {
            $email = trim((string) $contact->email);
            if ($email !== '') {
                $out[] = ['type' => 'contact', 'model' => $contact, 'name' => $contact->full_name ?: $email, 'email' => $email];
            }
        }

        foreach ($deal->providerParties()->wherePivot('role', $role)->get() as $provider) {
            $email = trim((string) $provider->email);
            if ($email !== '') {
                $out[] = ['type' => 'provider', 'model' => $provider, 'name' => $provider->name ?: $email, 'email' => $email];
            }
        }

        return $out;
    }

    private function existingDocumentForType(DealV2 $deal, int $documentTypeId): ?Document
    {
        return $deal->documents()
            ->where('document_type_id', $documentTypeId)
            ->latest()
            ->first();
    }

    private function generateIfCoc(DealV2 $deal, DealStageDocumentRule $rule, ?AgencyServiceProvider $provider, User $actor): ?Document
    {
        if (! $this->isCocType($rule->document_type_id)) {
            return null;
        }
        $specialty = $provider?->specialty ?: $rule->party_role;
        return $this->cocGenerator->generate($deal, $specialty, $provider, $actor);
    }

    private function isCocType(int $documentTypeId): bool
    {
        static $cocId = null;
        if ($cocId === null) {
            $cocId = (int) (\App\Models\DocumentType::where('slug', 'coc_request')->value('id') ?? 0);
        }
        return $cocId > 0 && $documentTypeId === $cocId;
    }

    private function skipReason(array $recipients, ?Document $doc, bool $canGenerate): ?string
    {
        if (empty($recipients)) {
            return 'No party of this role is on the deal yet.';
        }
        if (! $doc && ! $canGenerate) {
            return 'No document of this type is on the deal to send.';
        }
        return null;
    }

    private function uniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (DealDocumentDistribution::where('secure_token', $token)->exists());
        return $token;
    }
}
