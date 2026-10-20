<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Platform\SubscriptionAgreementCompleted;
use App\Models\Billing\AgencySubscription;
use App\Models\PlatformEsign\Document;
use App\Services\PlatformEsign\Agreement\AgreementDiscount;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Support\Facades\Log;

/**
 * The monthly fee quoted for more than the last band (recorded by CoreX when the agreement was sent, spec platform-esign §11.5a)
 * becomes the agency's custom amount in billing the moment the agreement is fully signed - nobody re-types it (Johan 2026-10-20,
 * audit M3). An agreement without a quoted fee, or one SIGNED in a band (the quote is recorded before the agency enters its
 * headcount), leaves billing alone (hotfix 2026-10-20).
 *
 * The custom amount is what the signed agreement adds up to: the quote plus additional branches, less a whole-term discount - a custom
 * amount replaces every billing line. Writes the same terms the owner's "custom amount" form writes (AgencyBillingController::update):
 * custom amount + note, discount cleared - so the D5 invariant (never both) holds; a discount for the first N months therefore cannot
 * be carried and the document says so. Idempotent: a replay writes the same values. SYNC, and failure-isolated: the agreement is
 * already signed, so a billing fault is logged on the document and in the log, never thrown back at the signer.
 */
class ApplyAgreedQuotedFee
{
    public function __construct(private EsignService $esign)
    {
    }

    public function handle(SubscriptionAgreementCompleted $event): void
    {
        $doc = Document::withoutGlobalScopes()->find($event->documentId);
        if (! $doc) {
            return;
        }
        $rr = (array) $doc->rr_data;
        if (AgreementPricing::quotedFee($rr) <= 0) {
            return;
        }

        try {
            $doc->loadMissing('wording');
            $calc = AgreementPricing::derive((array) ($doc->form_data ?? []), $rr, (array) ($doc->wording?->rates_json ?? []));
            if (($calc['plan'] ?? '') !== 'quoted') {
                $this->esign->log($doc, 'billing_quoted_fee_not_applied', 'Billing: the agreement was signed in a standard band, not at the quoted fee'
                    . ' - billing follows the standard bands', null, $event->actorUserId);

                return;
            }
            $discount = AgreementDiscount::fromRr($rr);
            $timeLimited = $discount !== null && $discount['months'] !== null;
            $amount = round((float) ($timeLimited ? $calc['full_total'] : $calc['total']), 2);

            $subscription = AgencySubscription::forAgency($event->agencyId);
            if (! $subscription->exists) {
                return;
            }
            $subscription->forceFill([
                'custom_amount_zar'  => $amount,
                'custom_amount_note' => 'Quoted monthly fee from the signed Subscription Agreement (document ' . $doc->id . ', '
                    . optional($doc->completed_at)->format('j M Y') . ')',
                'discount_percent'   => null,
                'discount_months'    => null,
                'discount_starts_on' => null,
                'discount_note'      => null,
            ])->save();
            $this->esign->log($doc, 'billing_quoted_fee_applied', 'Billing: the agreed monthly fee ' . AgreementPricing::rate($amount)
                . ' is now the agency\'s monthly amount', null, $event->actorUserId);
            if ($timeLimited) {
                $this->esign->log($doc, 'billing_quoted_fee_discount_not_applied', 'Billing: the agreed discount (' . AgreementDiscount::short($discount)
                    . ') cannot be combined with a custom monthly amount - credit it by hand under Admin > Billing', null, $event->actorUserId);
            }
        } catch (\Throwable $e) {
            Log::error('Billing: applying the agreed quoted fee failed', ['document_id' => $doc->id, 'agency_id' => $event->agencyId, 'error' => $e->getMessage()]);
            $this->esign->log($doc, 'billing_quoted_fee_failed', 'Billing: the quoted monthly fee could not be applied - set it by hand under Admin > Billing', null, $event->actorUserId);
        }
    }
}
