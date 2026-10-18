<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrderPhoto;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 4 — who is looking at a job
 * card through a crew link, and what they may see. ONE value object shared by
 * the per-job link (Build 1) and the crew page (Build 2), built ONLY from a
 * resolved, live token plus the agency's settings — never from anything in
 * the request URL or body (the agency/crew/card a link reaches is fixed by
 * the token row itself).
 */
final class CrewViewContext
{
    public const VIA_JOB_LINK = 'job_link';
    public const VIA_CREW_PAGE = 'crew_page';

    public function __construct(
        public readonly int $agencyId,
        public readonly ?int $crewId,
        public readonly int $tokenId,
        /** `job_link` | `crew_page` */
        public readonly string $via,
        /** §17.4.7 — the crew also sees the COST figures the office entered (never selling, markup or margin). */
        public readonly bool $showCosts,
        public readonly bool $showTenantContact,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        /** e.g. "via crew link — Team 1" — appended to every history line the crew's actions write. */
        public readonly string $actorLabel,
    ) {
    }

    /** The per-job link: the token IS the card, and the card's crew is the crew. */
    public static function forJobLink(RentalSecureAccessToken $token, RentalJobCard $card, ?Request $request = null): self
    {
        return self::build($token, $card, self::VIA_JOB_LINK, $request);
    }

    /** The crew page: the token IS the crew, and each opened card must be one of that crew's open cards. */
    public static function forCrewPage(RentalSecureAccessToken $token, RentalJobCard $card, ?Request $request = null): self
    {
        return self::build($token, $card, self::VIA_CREW_PAGE, $request);
    }

    private static function build(RentalSecureAccessToken $token, RentalJobCard $card, string $via, ?Request $request): self
    {
        $agencyId = (int) $token->agency_id;
        $crewName = $card->crew?->name;

        return new self(
            agencyId: $agencyId,
            crewId: $card->rental_crew_id ? (int) $card->rental_crew_id : ($token->rental_crew_id ? (int) $token->rental_crew_id : null),
            tokenId: (int) $token->id,
            via: $via,
            showCosts: RentalPortalSetting::crewLinkShowCostsFor($agencyId),
            showTenantContact: RentalPortalSetting::crewLinkShowTenantContactFor($agencyId),
            ip: $request?->ip(),
            userAgent: $request?->userAgent() !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            actorLabel: ($via === self::VIA_CREW_PAGE ? 'via crew page' : 'via crew link') . ($crewName ? " — {$crewName}" : ''),
        );
    }

    /** The value stored in rental_job_cards.worker_sign_off_via for a completion made through this context. */
    public function signOffVia(): string
    {
        return $this->via === self::VIA_CREW_PAGE ? RentalJobCard::SIGN_OFF_VIA_CREW_PAGE : RentalJobCard::SIGN_OFF_VIA_CREW_LINK;
    }

    /** The value stored in rental_work_order_photos.uploaded_via for a photo made through this context. */
    public function photoVia(): string
    {
        return $this->via === self::VIA_CREW_PAGE ? RentalWorkOrderPhoto::VIA_CREW_PAGE : RentalWorkOrderPhoto::VIA_CREW_LINK;
    }
}
