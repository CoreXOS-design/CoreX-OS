<?php

namespace App\Services\PublicLinks;

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\Leads\SharedLinkReengagementService;

/**
 * The ONE shared "valid link, dead resource" response builder for every
 * public, unauthenticated, token-addressed page in CoreX (Johan, 2026-08-25
 * — "reuse it — one shared view/handler, not five copies"). Agency-branded,
 * always offers a route back to a human — the standard built for the seller
 * live link, generalised so the next public link doesn't grow its own sixth
 * dialect of the same page.
 *
 * 2026-08-25 (Johan, round 2) — "the link should be a rich engaging page,
 * not a dead-looking screen." Upgraded to render the SAME visual standard
 * as shared/match-expired.blade.php (Johan pointed at that page three times
 * as the reference): agent card with business-card link/call/WhatsApp, a
 * couple of that agent's newest listings, a couple of the agency's newest —
 * every section absent, not empty, when there's nothing to show. The public
 * method signature is unchanged from the original version so every existing
 * caller (SellerLinkController, PublicAgencyPropertiesController,
 * AgentPreviewController, FicaPublicController, SellerInfoPublicController,
 * SalesDocumentController) keeps working without modification — only what
 * gets rendered got richer. $eyebrow/$secondaryAction are new, optional,
 * additive parameters.
 *
 * Deliberately NOT for the "genuinely unknown token" branch of the 3-branch
 * policy (.ai/audits/2026-08-24-public-link-resilience-audit.md Part 2) —
 * that branch has no agency to resolve and must stay wording-generic so a
 * token-prober can't tell "wrong token" from "revoked token" apart. That
 * branch is covered application-wide by bootstrap/app.php's HttpException
 * render() callback (errors.404-guest / errors.403-guest, extended to 410).
 * This responder is for the OTHER branch: a real record resolved and is
 * dead for a reason worth naming — where showing agency branding is safe
 * because the record's own existence is what makes the branding resolvable
 * at all.
 */
class PublicLinkUnavailableResponder
{
    public function __construct(private SharedLinkReengagementService $reengagement)
    {
    }

    /**
     * @param int|null $agencyId Resolve branding/contact from this agency. Null
     *   renders the card with CoreX-neutral styling only — used when even the
     *   dead record itself carries no agency_id.
     * @param string $title Honest, mode-specific heading — never generic
     *   filler shared across reasons that mean different things. Rendered as
     *   the page's headline.
     * @param string $body One or two sentences naming what actually happened —
     *   never WHY it died in a way that reveals private detail. Rendered as
     *   the page's supporting line.
     * @param User|null $agent A specific still-active agent to show as the
     *   contact, when one is resolvable and appropriate for this link. Falls
     *   back to the agency's own contact details when null or inactive.
     * @param array{label:string,url:string}|null $primaryAction An optional
     *   CTA button (e.g. "See current stock").
     * @param int $status HTTP status — 410 for "used to work, doesn't now"
     *   (the normal case here), 404 only when nothing about this resource
     *   was ever addressable at all.
     * @param string|null $eyebrow Small label above the headline (e.g. "THIS
     *   LISTING"). Defaults to the agency name when omitted, matching the
     *   original version's plain heading-only behaviour.
     * @param array{label:string,url:string}|null $secondaryAction A second,
     *   lower-emphasis link next to $primaryAction (e.g. "Browse what's available").
     */
    public function respond(
        ?int $agencyId,
        string $title,
        string $body,
        ?User $agent = null,
        ?array $primaryAction = null,
        int $status = 410,
        ?string $eyebrow = null,
        ?array $secondaryAction = null,
    ) {
        $agency = $agencyId ? Agency::withoutGlobalScopes()->find($agencyId) : null;

        $showAgent = $agent && $agent->is_active && $agent->deleted_at === null;
        $resolvedAgent = $showAgent ? $agent : null;

        $fallback = $agency
            ? $this->reengagement->agencyFallbackContact($agency)
            : ['phone' => null, 'email' => null];

        $agentListings = $resolvedAgent
            ? $this->recentListings(['agent_id' => $resolvedAgent->id])
            : collect();

        $agencyListings = $agency
            ? $this->recentListings(['agency_id' => $agency->id])
            : collect();

        return response()->view('public.shared._dead-end', [
            'eyebrow'         => $eyebrow ?? ($agency->name ?? 'CoreX'),
            'headline'        => $title,
            'lede'            => $body,
            'agency'          => $agency,
            'agent'           => $resolvedAgent,
            'agentCardUrl'    => $resolvedAgent ? $resolvedAgent->publicProfileUrl() : null,
            'agentListings'   => $agentListings,
            'agencyListings'  => $agencyListings,
            'agencyStockUrl'  => $agency ? url($agency->slug . '/properties') : null,
            'fallbackPhone'   => $fallback['phone'],
            'fallbackEmail'   => $fallback['email'],
            'primaryAction'   => $primaryAction,
            'secondaryAction' => $secondaryAction,
        ], $status);
    }

    /**
     * Two most recent public listings for an agent or an agency — same query
     * shape and status filters as SharedMatchController::showExpired() /
     * AgentPreviewController's own public profile, so "newest stock" always
     * agrees with what the "See all" link (the agent's/agency's own public
     * page) would show. Nothing here reads the dead record's own data —
     * these are plain public-stock lookups, agnostic of why the link died.
     */
    private function recentListings(array $scope)
    {
        $query = Property::withoutGlobalScopes()->whereNull('deleted_at');

        if (isset($scope['agent_id'])) {
            $query->where('agent_id', $scope['agent_id'])
                ->whereIn('status', ['active', 'pending', 'under_offer', 'sold'])
                ->orderByRaw("FIELD(status, 'active', 'pending', 'under_offer', 'sold')")
                ->latest('published_at');
        } else {
            $query->where('agency_id', $scope['agency_id'])
                ->whereIn('status', ['Active', 'NewListing', 'Reduced', 'active', 'new_listing', 'reduced'])
                ->orderByDesc('id');
        }

        $properties = $query->limit(2)->get();

        foreach ($properties as $property) {
            $property->display_image_url = $this->listingImageUrl($property);
        }

        return $properties;
    }

    /**
     * Best on-hand photo for a listing card — same resolution order as
     * SharedMatchController::listingImageUrl() / AgentPreviewController's
     * live-preview page (gallery → dawn → noon → dusk), so a listing looks
     * the same wherever it's shown.
     */
    private function listingImageUrl(Property $property): ?string
    {
        $img = collect(array_merge(
            $property->gallery_images_json ?? [],
            $property->dawn_images_json ?? [],
            $property->noon_images_json ?? [],
            $property->dusk_images_json ?? [],
        ))->filter()->first();

        if (!$img) {
            return null;
        }
        if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) {
            return $img;
        }
        $img = ltrim($img, '/');

        return str_starts_with($img, 'storage/') ? asset($img) : asset('storage/' . $img);
    }
}
