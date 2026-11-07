<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionSellerSubmission as S;
use App\Services\Auctions\AuctionSellerSubmissionService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The "Sell at auction" questionnaire's endpoint (.ai/specs/auctions.md §22): validates exactly as the form promises, then hands over to the service. */
class SellerSubmissionController extends Controller
{
    public const FEATURES = ['pool', 'borehole', 'solar', 'garden_flat', 'electric_fence', 'fibre', 'staff_quarters', 'double_garage'];
    public const DOCUMENTS = ['title_deed', 'rates_account', 'building_plans', 'levy_statement', 'lease_agreement', 'bond_statement', 'id_copies'];

    public function __construct(private PublicAuctionSite $site, private AuctionSellerSubmissionService $service) {}

    public function store(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        abort_unless(AgencyAuctionSettings::sellerFormEnabledFor((int) $agency->id), 404);

        // spam honeypot: a real person never sees this field
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['message' => 'This submission could not be accepted.'], 422);
        }

        $data = self::validated($request);

        $this->service->capture((int) $agency->id, $data, $request->ip());

        return response()->json(['ok' => true, 'message' => 'Questionnaire received.'], 201);
    }

    /**
     * The questionnaire's rules, exactly as the form promises them - shared with the auction website API so a website cannot send what the page would refuse.
     *
     * @return array<string, mixed> the validated answers (booleans normalised, honeypot excluded)
     */
    public static function validated(Request $request): array
    {
        $int = 'nullable|integer|min:0|max:9999999999';
        $data = $request->validate([
            'seller_type' => ['required', Rule::in(array_keys(S::SELLER_TYPES))],
            'spouse_name' => [Rule::requiredIf(fn () => $request->input('seller_type') === 'married_in_community'), 'nullable', 'string', 'max:120'],
            'executor_name' => [Rule::requiredIf(fn () => $request->input('seller_type') === 'deceased_estate'), 'nullable', 'string', 'max:120'],
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|max:30',
            'preferred_contact' => ['nullable', Rule::in(array_keys(S::CONTACT_METHODS))],

            'property_type' => ['required', Rule::in(array_keys(S::PROPERTY_TYPES))],
            'address' => 'required|string|max:255',
            'suburb' => 'required|string|max:120',
            'zoning' => 'nullable|string|max:60',
            'erf_size' => $int, 'floor_size' => $int,
            'bedrooms' => 'nullable|integer|min:0|max:50', 'bathrooms' => 'nullable|integer|min:0|max:50',
            'features' => 'nullable|array', 'features.*' => ['string', Rule::in(self::FEATURES)],

            'occupancy' => ['required', Rule::in(['owner', 'tenant', 'vacant'])],
            'monthly_rent' => [Rule::requiredIf(fn () => $request->input('occupancy') === 'tenant'), 'nullable', 'integer', 'min:0', 'max:9999999999'],
            'condition' => ['required', Rule::in(['excellent', 'good', 'fair', 'needs_work'])],
            'defects' => 'nullable|string|max:2000',

            'has_bond' => 'nullable|boolean',
            'bond_bank' => [Rule::requiredIf(fn () => $request->boolean('has_bond')), 'nullable', 'string', 'max:120'],
            'bond_amount' => $int,
            'bond_arrears' => 'nullable|boolean',
            'levies' => $int, 'municipal_valuation' => $int, 'price_expectation' => $int,

            'auction_format' => ['required', Rule::in(array_keys(S::FORMATS))],
            'reserve' => ['required', Rule::in(['confidential', 'none'])],
            'timing' => ['required', Rule::in(['asap', '1_3_months', '3_6_months', 'exploring'])],
            'viewing' => ['required', Rule::in(['most_days', 'appointment', 'restricted'])],
            'sale_reason' => 'nullable|string|max:500',

            'documents' => 'nullable|array', 'documents.*' => ['string', Rule::in(self::DOCUMENTS)],
            'referral_source' => 'nullable|string|max:190',

            'consent_terms' => 'accepted',
            'consent_authorised' => 'accepted',
            'consent_processing' => 'accepted',
            'marketing_opt_in' => 'nullable|boolean',
        ], [
            'consent_terms.accepted' => 'Please accept the terms to continue.',
            'consent_authorised.accepted' => 'Please confirm you are authorised to sell the property.',
            'consent_processing.accepted' => 'We need your consent to process this information.',
        ]);

        $data['marketing_opt_in'] = $request->boolean('marketing_opt_in');
        $data['has_bond'] = $request->boolean('has_bond');
        $data['bond_arrears'] = $request->boolean('bond_arrears');

        return $data;
    }
}
