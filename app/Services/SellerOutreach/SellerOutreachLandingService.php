<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Events\SellerOutreach\PitchClicked;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachClick;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\Prospecting\ProspectingConfigurationService;
use App\Services\Prospecting\ProspectingIntelligenceService;
use App\Support\SellerOutreach\LandingPageData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves the public landing page state from a tracking shortcode and
 * records clicks. Determines Active / Generic / Agent-Unavailable mode
 * per spec S8.
 */
final class SellerOutreachLandingService
{
    public function __construct(
        private readonly ProspectingIntelligenceService $intelligence,
        private readonly ProspectingConfigurationService $config,
    ) {}

    public function resolveLanding(string $shortCode): LandingPageData
    {
        $send = SellerOutreachSend::withoutGlobalScopes()
            ->where('tracking_short_code', $shortCode)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $agencyId = (int) $send->agency_id;

        $property = Property::withoutGlobalScopes()
            ->where('id', $send->property_id)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();

        $agent = $send->agent_id
            ? User::withoutGlobalScopes()
                ->where('id', $send->agent_id)
                ->whereNull('deleted_at')
                ->first()
            : null;

        $mode = $this->determineMode($property, $agent);
        $contactCard = $agent ?: $this->resolveBranchManagerFallback($agencyId);

        $townName = null;
        $liveBuyerCount = 0;
        $liveMatchingBuyerCount = 0;

        try {
            $listingType = $property?->listing_type ?? 'sale';
            $baseFilters = ['listing_type' => $listingType];

            $town = null;
            if ($property && !empty($property->suburb)) {
                $town = $this->config->suburbToTown($agencyId, (string) $property->suburb);
            } else {
                // Generic / Agent-Unavailable mode: property may be null because
                // it was archived after the pitch went out. Recover the town
                // from the send's frozen facts_snapshot so the seller still
                // sees the area demand they were originally pitched on. Spec S8.
                $townIdFromSnapshot = data_get($send->facts_snapshot, 'property_segments.town_id');
                if ($townIdFromSnapshot) {
                    $town = $this->config->towns($agencyId)->firstWhere('id', (int) $townIdFromSnapshot);
                }
            }
            if ($town) {
                $townName = $town->name;
            }

            // Same correctness fix as SellerOutreachComposerService: snapshot()'s
            // activeBuyers does not narrow by town/segment filters, so use
            // buyersForSegment() and intersect to get accurate per-claim counts.
            if ($town) {
                $townBuyerIds = $this->intelligence->buyersForSegment($agencyId, 'town', $town->id, $baseFilters);
                $liveBuyerCount = $townBuyerIds->count();
            } else {
                $townBuyerIds = null;
                $liveBuyerCount = $this->intelligence
                    ->snapshot(['agency_id' => $agencyId] + $baseFilters)
                    ->activeBuyers;
            }

            if ($mode === LandingPageData::MODE_ACTIVE && $property && $townBuyerIds !== null) {
                $matchingIds = $townBuyerIds;

                $propertyTypeOpt = !empty($property->property_type)
                    ? $this->config->propertyTypes($agencyId, activeOnly: false)
                        ->firstWhere('slug', Str::slug((string) $property->property_type))
                    : null;
                if ($propertyTypeOpt && $matchingIds->isNotEmpty()) {
                    $matchingIds = $matchingIds->intersect(
                        $this->intelligence->buyersForSegment($agencyId, 'property_type', $propertyTypeOpt->id, $baseFilters)
                    )->values();
                }

                // Properties table uses `beds`, not `bedrooms` — pre-flight confirmed.
                $bedroomSeg = isset($property->beds) ? $this->config->bedroomBucketFor($agencyId, (int) $property->beds) : null;
                if ($bedroomSeg && $matchingIds->isNotEmpty()) {
                    $matchingIds = $matchingIds->intersect(
                        $this->intelligence->buyersForSegment($agencyId, 'bedrooms', $bedroomSeg->id, $baseFilters)
                    )->values();
                }

                $priceBand = isset($property->price) && (int) $property->price > 0
                    ? $this->config->classifyPrice($agencyId, $listingType, (int) $property->price)
                    : null;
                if ($priceBand && $matchingIds->isNotEmpty()) {
                    $matchingIds = $matchingIds->intersect(
                        $this->intelligence->buyersForSegment($agencyId, 'price_band', $priceBand->id, $baseFilters)
                    )->values();
                }

                $liveMatchingBuyerCount = $matchingIds->count();
            }
        } catch (\Throwable $e) {
            Log::warning('SellerOutreachLandingService: live stats failed', [
                'error' => $e->getMessage(),
                'short_code' => $shortCode,
            ]);
            $liveBuyerCount = 0;
            $liveMatchingBuyerCount = 0;
            $townName = $property?->suburb;
        }

        $agencyName = DB::table('agencies')->where('id', $agencyId)->value('name');
        $agencyName = $agencyName ? (string) $agencyName : 'Our agency';

        return new LandingPageData(
            mode: $mode,
            send: $send,
            property: $mode === LandingPageData::MODE_ACTIVE ? $property : null,
            contactCard: $contactCard,
            agencyId: $agencyId,
            agencyName: $agencyName,
            townName: $townName,
            liveBuyerCount: $liveBuyerCount,
            liveMatchingBuyerCount: $liveMatchingBuyerCount,
            agentWhatsappUrl: $this->buildAgentWhatsappUrl($contactCard, $agencyId),
            agencyBlurb: $this->resolveAgencyBlurb($agencyId),
        );
    }

    public function recordClick(SellerOutreachSend $send, Request $request): SellerOutreachClick
    {
        $wasFirstClick = $send->first_clicked_at === null;

        $click = SellerOutreachClick::create([
            'agency_id' => $send->agency_id,
            'send_id' => $send->id,
            'clicked_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'geo_country' => null,
        ]);

        if ($wasFirstClick) {
            $send->update([
                'first_clicked_at' => $click->clicked_at,
                'outcome' => SellerOutreachSend::OUTCOME_CLICKED,
            ]);

            // AT-81 — a click is engagement: clear any pending consent-request so
            // the no-response timeout never lapses a contact who interacted. Public
            // (unauthenticated) path → resolve the contact agency-scoped.
            $contact = \App\Models\Contact::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('id', $send->contact_id)
                ->where('agency_id', $send->agency_id)
                ->first();
            $contact?->clearOutreachPending();
        }

        event(new PitchClicked(
            click: $click,
            send: $send,
            agencyId: (int) $send->agency_id,
            isFirstClick: $wasFirstClick,
        ));

        return $click;
    }

    private function determineMode(?Property $property, ?User $agent): string
    {
        if (!$agent) {
            return LandingPageData::MODE_AGENT_UNAVAILABLE;
        }
        if (!$property) {
            return LandingPageData::MODE_GENERIC;
        }
        return LandingPageData::MODE_ACTIVE;
    }

    private function resolveBranchManagerFallback(int $agencyId): User
    {
        $user = User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['super_admin', 'admin'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if (!$user) {
            $user = new User(['name' => 'The team']);
            $user->id = 0;
        }
        return $user;
    }

    private function buildAgentWhatsappUrl(User $contactCard, int $agencyId): string
    {
        $phone = $contactCard->phone ?? $contactCard->cell ?? null;
        if (!$phone) {
            return '#';
        }
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (!$digits) {
            return '#';
        }
        if (str_starts_with($digits, '0')) {
            $digits = '27' . substr($digits, 1);
        }

        // 2026-05-14 hotfix: seller-side launch mode is a separate agency
        // setting (default `whatsapp_web` — safer for sellers who may not
        // have WhatsApp installed).
        $mode = $this->resolveAgencyWhatsappMode($agencyId, 'seller');

        return $mode === \App\Models\Agency::WHATSAPP_LAUNCH_APP
            ? "whatsapp://send?phone={$digits}"
            : "https://wa.me/{$digits}";
    }

    private function resolveAgencyWhatsappMode(int $agencyId, string $side): string
    {
        static $cache = [];
        $key = "{$agencyId}:{$side}";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $column = $side === 'agent' ? 'whatsapp_launch_mode_agent' : 'whatsapp_launch_mode_seller';
        $value = DB::table('agencies')->where('id', $agencyId)->value($column);
        $cache[$key] = in_array($value, [
            \App\Models\Agency::WHATSAPP_LAUNCH_APP,
            \App\Models\Agency::WHATSAPP_LAUNCH_WEB,
        ], true) ? (string) $value : \App\Models\Agency::WHATSAPP_LAUNCH_WEB;
        return $cache[$key];
    }

    private function resolveAgencyBlurb(int $agencyId): string
    {
        return 'We connect serious buyers with sellers across South Africa.';
    }
}
