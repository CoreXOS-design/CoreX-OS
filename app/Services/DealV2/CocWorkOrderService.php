<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealStepWorkOrder;
use App\Models\DealV2\DealV2;
use App\Models\User;

/**
 * AT-229 COC sub-process — resolves the RESPONSIBLE PARTY of a per-deal work order to a real
 * recipient (email), CCs the listing + selling agents (de-duped), and sends via the shipped
 * AT-228 distribution path. One work order → one email (per unique address) → one audit.
 */
class CocWorkOrderService
{
    public function __construct(
        private WorkAuthorisationGenerator $generator,
        private Dr2DistributionSendService $sender,
    ) {
    }

    /** Responsible-party options for the UI (value => label). */
    public static function responsibleLabels(): array
    {
        return [
            'seller'            => 'Seller (self-handling)',
            'listing_agent'     => 'Listing agent',
            'selling_agent'     => 'Selling agent',
            'supplier'          => 'Supplier',
            'transfer_attorney' => 'Transferring attorney',
        ];
    }

    /**
     * Resolve the responsible party to a send target.
     *
     * @return array{type:string,id:?int,name:?string,email:?string}
     *   type: 'contact' (seller) | 'provider' (supplier/attorney) | 'agent' (listing/selling)
     */
    public function resolveRecipient(Deal $deal, DealStepWorkOrder $wo): array
    {
        switch ($wo->responsible_party) {
            case 'seller':
                $c = $deal->sellers()->first();
                return ['type' => 'contact', 'id' => $c?->id, 'name' => $c?->full_name, 'email' => $c?->email];

            case 'listing_agent':
                $u = $deal->listingAgents()->first();
                return ['type' => 'agent', 'id' => $u?->id, 'name' => $u?->name, 'email' => $u?->outward_email ?: $u?->email];

            case 'selling_agent':
                $u = $deal->sellingAgents()->first();
                return ['type' => 'agent', 'id' => $u?->id, 'name' => $u?->name, 'email' => $u?->outward_email ?: $u?->email];

            case 'transfer_attorney':
                $p = $this->transferAttorney($deal) ?? ($wo->service_provider_id ? AgencyServiceProvider::find($wo->service_provider_id) : null);
                return ['type' => 'provider', 'id' => $p?->id, 'name' => $p?->name, 'email' => $p?->email];

            case 'supplier':
            default:
                $p = $wo->service_provider_id ? AgencyServiceProvider::find($wo->service_provider_id) : null;
                return ['type' => 'provider', 'id' => $p?->id, 'name' => $p?->name, 'email' => $p?->email];
        }
    }

    /** Listing + selling agents (item 4), de-duped and MINUS the primary recipient (item 5). */
    public function ccList(Deal $deal, ?string $primaryEmail): array
    {
        $emails = [];
        foreach ([$deal->listingAgents()->first(), $deal->sellingAgents()->first()] as $u) {
            $e = trim((string) ($u?->outward_email ?: $u?->email ?: ''));
            if ($e !== '') {
                $emails[strtolower($e)] = $e; // key by address → listing==selling collapses to one
            }
        }
        if ($primaryEmail) {
            unset($emails[strtolower(trim($primaryEmail))]); // never CC the primary
        }
        return array_values($emails);
    }

    /**
     * Send one work order: build the authorisation PDF, address the responsible party, CC the
     * agents (de-duped), audit through AT-228, and stamp the row sent. Throws on no address.
     */
    public function send(DealStepWorkOrder $wo, User $actor, array $fieldOverrides = []): void
    {
        $deal = $wo->stepInstance?->dr1Deal ?? Deal::find($wo->dr1_deal_id);
        if (! $deal) {
            throw new \DomainException('Work order is not linked to a deal.');
        }

        // §17 — mint the DR2 twin BEFORE generating the PDF. On a deal with no twin
        // yet, the document must be generated with the twin present or it lands
        // outside the deal's document corpus and sendToParty rejects it ("select at
        // least one document"). ensureTwin sets deal_v2_id on the model in place; do
        // NOT refresh() the deal (that clears the property relation the corpus needs).
        if (! $deal->deal_v2_id) {
            app(DealSyncService::class)->ensureTwin($deal);
        }

        $recipient = $this->resolveRecipient($deal, $wo);
        $label = self::responsibleLabels()[$wo->responsible_party] ?? $wo->responsible_party;
        if (empty($recipient['email'])) {
            throw new \DomainException("No email on file for the {$label} — add one or choose another responsible party.");
        }

        $cc = $this->ccList($deal, $recipient['email']);

        $fields = array_merge(
            $this->generator->defaultFields($deal, $wo->service_type),
            array_filter($fieldOverrides, fn ($v) => $v !== null && $v !== '')
        );
        $provider = ($recipient['type'] === 'provider' && $recipient['id']) ? AgencyServiceProvider::find($recipient['id']) : null;

        $document = $this->generator->generate($deal, $fields, $provider, $actor, $wo->stepInstance?->id);

        // AT-228: mints twin, attaches PDF, audits deal_document_distributions.
        $this->sender->sendToParty(
            $deal,
            'coc_' . $wo->responsible_party,
            [
                'type'  => $recipient['type'] === 'contact' ? 'contact' : ($recipient['type'] === 'provider' ? 'provider' : 'agent'),
                'id'    => in_array($recipient['type'], ['contact', 'provider'], true) ? $recipient['id'] : null,
                'name'  => $recipient['name'] ?: $label,
                'email' => $recipient['email'],
            ],
            [$document->id],
            DealDocumentDistribution::MODE_DIRECT_ATTACHMENT,
            DealDocumentDistribution::CHANNEL_EMAIL,
            'Please find the attached work order.',
            $actor,
            $cc,
        );

        $wo->forceFill([
            'status'          => 'sent',
            'document_id'     => $document->id,
            'recipient_name'  => $recipient['name'] ?: $label,
            'recipient_email' => $recipient['email'],
            'cc_emails'       => $cc ? implode(', ', $cc) : null,
            'sent_at'         => now(),
            'sent_by_id'      => $actor->id,
        ])->save();
    }

    /** The transfer/bond attorney firm on the deal's DealV2 twin (DR1 deals carry no direct link). */
    private function transferAttorney(Deal $deal): ?AgencyServiceProvider
    {
        if (! $deal->deal_v2_id) {
            return null;
        }
        $twin = DealV2::withoutGlobalScopes()->with('providerParties')->find($deal->deal_v2_id);
        return $twin?->providerParties
            ->first(fn ($p) => in_array($p->pivot->role ?? '', ['transfer_attorney', 'conveyancer', 'bond_attorney', 'attorney'], true));
    }
}
