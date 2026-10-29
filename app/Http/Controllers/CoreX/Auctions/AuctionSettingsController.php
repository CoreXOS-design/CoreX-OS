<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use Illuminate\Http\Request;

/**
 * Screen S8 — Settings → Auctions (.ai/specs/auctions.md §7). updateWizard() is the Setup Wizard saver:
 * the wizard step posts a SUBSET of these fields, so every write there is guarded with $request->has()
 * (wizard spec §6.1) — an absent field is never wiped.
 */
class AuctionSettingsController extends Controller
{
    public function show(Request $request)
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        return view('corex.settings.auctions', [
            'agencyId' => $agencyId,
            'values' => [
                'site_title' => AgencyAuctionSettings::siteTitleFor($agencyId),
                'intro_text' => AgencyAuctionSettings::introTextFor($agencyId),
                'contact_email' => AgencyAuctionSettings::contactEmailFor($agencyId),
                'contact_phone' => AgencyAuctionSettings::contactPhoneFor($agencyId),
                'reference_prefix' => AgencyAuctionSettings::referencePrefixFor($agencyId),
                'soft_close_minutes' => AgencyAuctionSettings::softCloseMinutesFor($agencyId),
                'reserve_visibility' => AgencyAuctionSettings::reserveVisibilityFor($agencyId),
                'show_bid_count' => AgencyAuctionSettings::showBidCountFor($agencyId),
                'show_bidder_numbers' => AgencyAuctionSettings::showBidderNumbersFor($agencyId),
                'registration_closes_hours' => AgencyAuctionSettings::registrationClosesHoursFor($agencyId),
                'docs_required_individual' => AgencyAuctionSettings::docsRequiredIndividualFor($agencyId),
                'docs_required_company' => AgencyAuctionSettings::docsRequiredCompanyFor($agencyId),
                'terms_text' => AgencyAuctionSettings::termsTextFor($agencyId),
                'notify_enquiry_to' => AgencyAuctionSettings::notifyEnquiryToFor($agencyId),
                'outbid_emails' => AgencyAuctionSettings::outbidEmailsFor($agencyId),
                'confirmation_days' => AgencyAuctionSettings::confirmationDaysFor($agencyId),
                'how_it_works' => AgencyAuctionSettings::howItWorksFor($agencyId),
                'footer_text' => AgencyAuctionSettings::footerTextFor($agencyId),
                'show_past_auctions' => AgencyAuctionSettings::showPastAuctionsFor($agencyId),
                'default_increments' => AgencyAuctionSettings::defaultIncrementsFor($agencyId),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $data = $request->validate($this->rules());
        $write = $this->scalarWrites($data) + [
            'show_bid_count' => $request->boolean('show_bid_count'),
            'show_bidder_numbers' => $request->boolean('show_bidder_numbers'),
            'outbid_emails' => $request->boolean('outbid_emails'),
            'show_past_auctions' => $request->has('show_past_auctions') ? $request->boolean('show_past_auctions') : AgencyAuctionSettings::showPastAuctionsFor($agencyId),
            'docs_required_individual' => array_values($data['docs_required_individual'] ?? []),
            'docs_required_company' => array_values($data['docs_required_company'] ?? []),
            'default_increments' => $this->rungs($request) ?: null,
        ];

        AgencyAuctionSettings::updateOrCreate(['agency_id' => $agencyId], $write);

        return back()->with('status', 'Auction settings saved.');
    }

    /** Setup Wizard saver — writes only the fields the step actually posted. */
    public function updateWizard(Request $request): void
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        abort_unless($request->user()->hasPermission('auctions.manage_settings'), 403);

        $data = $request->validate($this->rules(wizard: true));

        $write = $this->scalarWrites($data, guardWithHas: $request);
        foreach (['show_bid_count', 'show_bidder_numbers', 'outbid_emails', 'show_past_auctions'] as $bool) {
            if ($request->has($bool)) {
                $write[$bool] = $request->boolean($bool);
            }
        }

        if ($write) {
            AgencyAuctionSettings::updateOrCreate(['agency_id' => $agencyId], $write);
        }
    }

    private function rules(bool $wizard = false): array
    {
        $p = $wizard ? 'sometimes|' : '';
        $kinds = implode(',', array_keys(AgencyAuctionSettings::DOCUMENT_KINDS));

        return [
            'site_title' => $p . 'nullable|string|max:160',
            'intro_text' => $p . 'nullable|string|max:2000',
            'contact_email' => $p . 'nullable|email|max:190',
            'contact_phone' => $p . 'nullable|string|max:40',
            'reference_prefix' => $p . 'nullable|string|max:12|regex:/^[A-Za-z0-9]+$/',
            'soft_close_minutes' => $p . 'nullable|integer|min:0|max:60',
            'reserve_visibility' => $p . 'nullable|in:' . implode(',', array_keys(AgencyAuctionSettings::RESERVE_VISIBILITY)),
            'registration_closes_hours' => $p . 'nullable|integer|min:0|max:720',
            'confirmation_days' => $p . 'nullable|integer|min:1|max:60',
            'how_it_works' => $p . 'nullable|string|max:5000',
            'footer_text' => $p . 'nullable|string|max:1000',
            'terms_text' => $p . 'nullable|string|max:20000',
            'notify_enquiry_to' => $p . 'nullable|in:' . implode(',', array_keys(AgencyAuctionSettings::NOTIFY_ENQUIRY_TO)),
            'docs_required_individual' => 'sometimes|array',
            'docs_required_individual.*' => 'in:' . $kinds,
            'docs_required_company' => 'sometimes|array',
            'docs_required_company.*' => 'in:' . $kinds,
        ];
    }

    /** @return array<string, mixed> */
    private function scalarWrites(array $data, ?Request $guardWithHas = null): array
    {
        $out = [];
        foreach (['site_title', 'intro_text', 'contact_email', 'contact_phone', 'reference_prefix', 'soft_close_minutes', 'reserve_visibility', 'registration_closes_hours', 'confirmation_days', 'how_it_works', 'footer_text', 'terms_text', 'notify_enquiry_to'] as $key) {
            if ($guardWithHas && ! $guardWithHas->has($key)) {
                continue;
            }
            $v = $data[$key] ?? null;
            $out[$key] = $key === 'reference_prefix' && $v !== null ? strtoupper($v) : ($v === '' ? null : $v);
        }

        return $out;
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function rungs(Request $request): array
    {
        $rungs = [];
        foreach ((array) $request->input('inc_from', []) as $i => $from) {
            $inc = $request->input('inc_amount.' . $i);
            if (is_numeric($from) && is_numeric($inc) && (float) $inc > 0 && (float) $from >= 0) {
                $rungs[(string) (float) $from] = [(float) $from, (float) $inc];
            }
        }
        ksort($rungs, SORT_NUMERIC);

        return array_values($rungs);
    }
}
