<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionSellerSubmitted;
use App\Models\AuctionSellerSubmission;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Support\Facades\Log;

/**
 * A "Sell at auction" questionnaire from the public site (.ai/specs/auctions.md §22). Stores it, then connects it to the pillars: the person
 * becomes (or is matched to) a Contact assigned to the agency's administrator, the property goes through the universal match-or-create (NN #10)
 * into Tracked Properties AND becomes a Property with status "Auction enquiry" (owner = that contact, agent = the administrator) that shows
 * under the Properties page's "Auction enquiries" tile, and AuctionSellerSubmitted tells the team. A failure to link never loses the submission - it is already stored.
 */
class AuctionSellerSubmissionService
{
    public function __construct(private AuctionContactResolver $contacts, private TrackedPropertyMatchOrCreateService $properties) {}

    private const TYPE_NAMES = ['house' => 'House', 'townhouse' => 'Townhouse', 'apartment' => 'Apartment / Flat', 'vacant_land' => 'Vacant Land / Plot', 'farm' => 'Farm', 'commercial' => 'Commercial Property', 'industrial' => 'Industrial Property'];

    /**
     * The Property record behind the questionnaire: status "Auction enquiry" (off-market, never syndicated, its own tile on the Properties page),
     * agent = the agency's administrator. The same address sent again does not make a second property: it finds the one already made.
     */
    private function propertyFor(int $agencyId, AuctionSellerSubmission $submission, array $data, ?string $number, string $street): ?Property
    {
        $tracked = $submission->tracked_property_id ? \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->find($submission->tracked_property_id) : null;
        if ($tracked && ! empty($tracked->promoted_to_property_id)) {
            return Property::withoutGlobalScopes()->where('agency_id', $agencyId)->find($tracked->promoted_to_property_id);
        }
        $externalId = $tracked ? 'auction-seller:tp:' . $tracked->id : 'auction-seller:' . $submission->id;
        $existing = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->where('external_id', $externalId)->first();
        if ($existing) {
            return $existing;
        }

        $admin = app(AuctionActivityRecipients::class)->defaultOwner($agencyId);
        if (! $admin) {
            return null;   // an agency with no active administrator has nobody to own it; the submission and tracked property are still kept
        }

        $beds = (int) ($data['bedrooms'] ?? 0);
        $typeName = self::TYPE_NAMES[$data['property_type']] ?? 'House';
        $title = ($beds > 0 ? $beds . '-bedroom ' : '') . strtolower($typeName === 'Apartment / Flat' ? 'apartment' : ($typeName === 'Vacant Land / Plot' ? 'vacant land' : $typeName)) . ' in ' . $data['suburb'];

        return Property::withoutAgencyStamping(fn () => Property::create([
            'agency_id' => $agencyId,
            'branch_id' => $admin->branch_id,
            'agent_id' => $admin->id,
            'status' => Property::STATUS_AUCTION_ENQUIRY,
            'external_id' => $externalId,
            'title' => ucfirst($title),
            'address' => $data['address'] . ', ' . $data['suburb'],
            'street_number' => $number,
            'street_name' => $street,
            'suburb' => $data['suburb'],
            'price' => (float) ($data['price_expectation'] ?? 0),
            'beds' => $beds,
            'baths' => (int) ($data['bathrooms'] ?? 0),
            'garages' => in_array('double_garage', (array) ($data['features'] ?? []), true) ? 2 : 0,
            'size_m2' => $data['floor_size'] ?? null,
            'erf_size_m2' => $data['erf_size'] ?? null,
            'property_type' => $typeName,
            'listing_type' => 'sale',
            'sale_method' => 'auction',
        ]));
    }

    /** @param array<string, mixed> $data the validated answers (no honeypot) */
    public function capture(int $agencyId, array $data, ?string $ip): AuctionSellerSubmission
    {
        $submission = AuctionSellerSubmission::withoutAgencyStamping(fn () => AuctionSellerSubmission::create([
            'agency_id' => $agencyId,
            'status' => AuctionSellerSubmission::STATUS_NEW,
            'seller_type' => $data['seller_type'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'preferred_contact' => $data['preferred_contact'] ?? 'phone',
            'property_type' => $data['property_type'],
            'address' => $data['address'],
            'suburb' => $data['suburb'],
            'price_expectation' => $data['price_expectation'] ?? null,
            'auction_format' => $data['auction_format'] ?? null,
            'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
            'ip' => $ip,
            'answers' => $data,
        ]));

        try {
            [$contact] = $this->contacts->resolve($agencyId, $data['name'], $data['email'], $data['phone'], null, null, null, 'Prospective Seller', 'Auto-created from the "Sell at auction" form on the public auction site.');
            $submission->forceFill(['contact_id' => $contact->id])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Seller submission contact not linked: ' . $e->getMessage(), ['submission' => $submission->id]);
        }

        // "12 Ocean Drive" -> number 12, street "Ocean Drive": the Tracked Property keeps the pieces (the free-text address is only used for matching)
        $street = trim((string) $data['address']);
        $number = null;
        if (preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/u', $street, $m)) {
            [$number, $street] = [$m[1], trim($m[2])];
        }

        try {
            $tracked = $this->properties->matchOrCreate(
                agencyId: $agencyId,
                facts: array_filter([
                    'address' => $data['address'],
                    'street_number' => $number,
                    'street_name' => $street,
                    'suburb' => $data['suburb'],
                    'property_type' => $data['property_type'],
                    'bedrooms' => $data['bedrooms'] ?? null,
                    'bathrooms' => $data['bathrooms'] ?? null,
                    'erf_size_m2' => $data['erf_size'] ?? null,
                    'floor_size_m2' => $data['floor_size'] ?? null,
                    'municipal_valuation' => $data['municipal_valuation'] ?? null,
                    'last_known_asking_price' => $data['price_expectation'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
                source: ['type' => 'auction_seller_form', 'ref' => 'seller-submission:' . $submission->id, 'payload' => ['submission_id' => $submission->id, 'seller_type' => $data['seller_type']]],
                actorUserId: null,
            );
            $submission->forceFill(['tracked_property_id' => $tracked->id])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Seller submission property not linked: ' . $e->getMessage(), ['submission' => $submission->id]);
        }

        try {
            $property = $this->propertyFor($agencyId, $submission, $data, $number, $street);
            if ($property) {
                $submission->forceFill(['property_id' => $property->id])->saveQuietly();
                if ($submission->contact_id) {
                    ContactPropertyLinker::link((int) $submission->contact_id, (int) $property->id, 'owner');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Seller submission property record not created: ' . $e->getMessage(), ['submission' => $submission->id]);
        }

        AuctionSellerSubmitted::dispatch($submission->fresh());

        return $submission;
    }
}
