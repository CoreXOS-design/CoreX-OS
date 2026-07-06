<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;

/**
 * Buyer-lifecycle loop — THE single shared seed-and-land method.
 *
 * A portal enquiry (P24/PP) or a manual capture tied to a listing is the strongest
 * possible buyer signal — the person raised their hand on a specific property. This
 * service converts that signal into a fully-formed pipeline buyer by deriving a
 * criteria-bearing wishlist (ContactMatch) from the enquired property using the
 * EXISTING configured tolerance (AgencyContactSettings::micPriceBandFraction — the
 * same band MatchingService scores against; no new tolerance invented).
 *
 * Creating that countable ContactMatch is the ONE cascade point: ContactMatchObserver
 * ::created → BuyerStateService::landOnPipeline (is_buyer + buyer_state='new' + audit),
 * and ContactMatchObserver::saved → RegenerateBuyerMatchesJob → prospecting_buyer_matches
 * (MIC demand). We do NOT fork that machinery — every entry point (P24, PP, manual)
 * funnels through here (or, for pure-criteria manual capture, through the same observer
 * via tagBuyerSource()).
 *
 * Source is tagged (portal_p24 | portal_pp | manual) so MIC demand stays separable and
 * never blended (Johan's two-streams rule). First-write-wins on the buyer's origin.
 */
final class BuyerLeadCascadeService
{
    public const SOURCE_PORTAL_P24     = 'portal_p24';
    public const SOURCE_PORTAL_PP      = 'portal_pp';
    public const SOURCE_PORTAL_WEBSITE = 'portal_website';
    public const SOURCE_MANUAL         = 'manual';

    /**
     * Seed a derived wishlist from the enquired/selected property and let the existing
     * observer cascade land the buyer + feed MIC. Returns the created ContactMatch, or
     * null when skipped (auto-seed off, listing too thin, or an equivalent seed exists).
     *
     * The buyer is flagged + source-tagged + given the enquiry note REGARDLESS of the
     * auto-seed toggle; only the derived-wishlist cascade is gated by it.
     */
    public function seedFromListing(
        Contact $contact,
        Property $listing,
        int $ownerAgentId,
        string $source,
        ?string $enquiryMessage = null,
    ): ?ContactMatch {
        $agencyId = (int) $contact->agency_id;

        // 1. Flag + source-tag the buyer and carry the enquiry context (single save).
        $this->prepareBuyerContact($contact, $source, $enquiryMessage, $listing);

        // 2. Agency toggle (default ON). Off ⇒ buyer flagged but no auto-seed/land/demand.
        if (! AgencyContactSettings::forAgency($agencyId)->portalLeadAutoSeedBuyer()) {
            return null;
        }

        // 3. Derive countable criteria from the listing using the EXISTING price band.
        $criteria = $this->deriveCriteria($listing, $agencyId);
        if ($criteria === null) {
            return null; // listing carries nothing we can make a countable wishlist from
        }

        // 4. Idempotency — a re-polled P24 batch or re-fired webhook must not pile up
        //    duplicate identical seeds.
        if ($this->equivalentSeedExists($contact, $criteria)) {
            return null;
        }

        // 5. Create the wishlist → ContactMatchObserver auto-lands pipeline + feeds MIC.
        return ContactMatch::create(array_merge($criteria, [
            'agency_id'          => $agencyId,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $ownerAgentId,        // pipeline owner (listing agent for portals)
            'status'             => ContactMatch::STATUS_ACTIVE,
            'is_primary'         => false,
            'name'               => $this->seedName($listing),
        ]));
    }

    /**
     * Tag a buyer's entry origin (first-write-wins) and mark them a buyer. Used by the
     * pure-criteria manual-capture paths, which already create their own ContactMatch
     * (so the observer cascade fires) — they only need the source stamped so MIC demand
     * is attributable. Safe to call repeatedly.
     */
    public function tagBuyerSource(Contact $contact, string $source): void
    {
        $dirty = [];
        if (blank($contact->buyer_source)) {
            $contact->buyer_source = $source;
            $dirty[] = 'buyer_source';
        }
        if (! $contact->is_buyer) {
            $contact->is_buyer = true;
            $dirty[] = 'is_buyer';
        }
        if ($dirty) {
            $contact->saveQuietly();
        }
    }

    /** Set buyer flag + source + enquiry note in one quiet save (no Contact observers). */
    private function prepareBuyerContact(Contact $contact, string $source, ?string $enquiryMessage, Property $listing): void
    {
        if (blank($contact->buyer_source)) {
            $contact->buyer_source = $source;
        }
        if (! $contact->is_buyer) {
            $contact->is_buyer = true;
        }

        $note = $this->buildEnquiryNote($listing, $enquiryMessage);
        if ($note !== null) {
            $existing = trim((string) $contact->buyer_pipeline_notes);
            // Don't duplicate the same enquiry note on a re-fire.
            if ($existing === '' || ! str_contains($existing, $note)) {
                $contact->buyer_pipeline_notes = $existing === '' ? $note : ($note . "\n\n" . $existing);
            }
        }

        $contact->saveQuietly();
    }

    private function buildEnquiryNote(Property $listing, ?string $enquiryMessage): ?string
    {
        $addr = method_exists($listing, 'buildDisplayAddress')
            ? $listing->buildDisplayAddress()
            : trim((string) ($listing->suburb ?? ''));
        $header = '[Enquiry] ' . (now()->format('j M Y')) . ' — re: ' . ($addr ?: ('listing #' . $listing->id));
        $msg = trim((string) $enquiryMessage);
        return $msg !== '' ? ($header . "\n" . $msg) : $header;
    }

    /**
     * Derive a countable wishlist from a listing. Area = the listing's P24 suburb,
     * price = listing price ± the configured band, beds = listing beds, type = listing
     * type. Returns null when the listing is too thin to make a countable wishlist.
     *
     * @return array<string, mixed>|null
     */
    private function deriveCriteria(Property $listing, int $agencyId): ?array
    {
        $f = AgencyContactSettings::forAgency($agencyId)->micPriceBandFraction();

        $suburbId = $listing->p24_suburb_id ? (int) $listing->p24_suburb_id : null;
        $price    = $listing->price ? (int) $listing->price : null;
        $type     = $listing->property_type ?: null;
        $beds     = $listing->beds ? (int) $listing->beds : null;

        $criteria = array_filter([
            'listing_type'   => $listing->listing_type ?: 'sale',
            'category'       => $listing->category ?: null,
            'property_type'  => $type,
            'property_types' => $type ? [$type] : null,
            'p24_suburb_ids' => $suburbId ? [$suburbId] : null,
            'price_min'      => $price ? (int) floor($price * (1 - $f)) : null,
            'price_max'      => $price ? (int) ceil($price * (1 + $f)) : null,
            'beds_min'       => $beds,
        ], fn ($v) => $v !== null && $v !== []);

        // Countable iff ≥1 of: area | price_band | beds | property_type.
        $hasGroup = isset($criteria['p24_suburb_ids'])
            || isset($criteria['price_min'])
            || isset($criteria['beds_min'])
            || isset($criteria['property_type']);

        return $hasGroup ? $criteria : null;
    }

    /** True when the contact already has an active wishlist equivalent to this seed. */
    private function equivalentSeedExists(Contact $contact, array $criteria): bool
    {
        $candidates = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->where('listing_type', $criteria['listing_type'] ?? 'sale')
            ->when(isset($criteria['price_min']), fn ($q) => $q->where('price_min', $criteria['price_min']))
            ->when(isset($criteria['price_max']), fn ($q) => $q->where('price_max', $criteria['price_max']))
            ->get();

        $targetSuburbs = array_values(array_map('intval', $criteria['p24_suburb_ids'] ?? []));
        sort($targetSuburbs);

        foreach ($candidates as $m) {
            $sub = array_values(array_map('intval', (array) ($m->p24_suburb_ids ?? [])));
            sort($sub);
            if ($sub === $targetSuburbs) {
                return true;
            }
        }
        return false;
    }

    private function seedName(Property $listing): string
    {
        $type = $listing->property_type ?: 'Property';
        $suburb = $listing->suburb ?: 'area';
        return trim(ucfirst((string) $type) . ' in ' . $suburb . ' (from enquiry)');
    }
}
