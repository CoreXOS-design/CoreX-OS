<?php

declare(strict_types=1);

namespace App\Support\Activity;

/**
 * M6.5 — single source of truth for user-facing labels of the
 * activity-points catalogue. Reused by the SPINE-SETTINGS admin screen,
 * the daily activity / daily summary screens, and any future surface
 * that has to render an action name.
 *
 * The seeder names rows "[Auto] deal.listing_side" — that's a dev
 * artifact, not a UI label. Lookup here turns slugs / event_class keys
 * into the copy agents actually see.
 *
 * New slugs not in the maps fall back to a generic prettifier so the
 * screen never shows a raw dotted slug.
 */
final class ActivityLabelResolver
{
    /**
     * Human-readable label for each instant slug (SPINE-1/2/3/2.5).
     * Multi-actor SPINE-2.5 role slugs are named to surface the role
     * an agent's row represents (e.g. "Deal registered — selling side").
     */
    public const SLUG_LABEL = [
        'contact.captured'                  => 'Contact captured',
        'property.captured'                 => 'Property captured',
        'property.published'                => 'Property published',
        'property.compliance_passed'        => 'Property compliance snapshot taken',
        'deal.created'                      => 'Deal captured',
        'deal.listing_side'                 => 'Deal captured — listing side',
        'deal.selling_side'                 => 'Deal captured — selling side',
        'deal.stage_advanced'               => 'Deal stage advanced',
        'deal.registered'                   => 'Deal registered',
        'deal.registered.listing_side'      => 'Deal registered — listing side',
        'deal.registered.selling_side'      => 'Deal registered — selling side',
        'deal.commission_finalised'         => 'Deal commission finalised',
        'mandate.signed'                    => 'Mandate signed',
        'presentation.generated'            => 'Presentation generated',
        'presentation.won'                  => 'Presentation won',
        'presentation.lost'                 => 'Presentation lost',
        'outreach.pitch_sent'               => 'Seller-outreach pitch sent',
        'outreach.outcome_logged'           => 'Seller-outreach outcome logged',
        'mic.claim_taken'                   => 'MIC claim taken',
        'mic.claim_feedback'                => 'MIC claim feedback recorded',
        'map.prospect_launched'             => 'Map prospect launched',
        'tracked_property.promoted_to_stock'=> 'Tracked property promoted to stock',
        'fica.submitted'                    => 'FICA submitted',
        'fica.approved'                     => 'FICA approved',
        'fica.reviewed'                     => 'FICA reviewed',
        'rcr.submitted'                     => 'RCR submission submitted',
        'marketing.published'               => 'Marketing post published',
    ];

    /**
     * Calendar event_class → user-facing label. M6.3 calendar entries
     * carry an event_class rather than a slug; this matches the row.
     */
    public const CALENDAR_LABEL = [
        'meeting'              => 'Meeting',
        'property_evaluation'  => 'Property evaluation',
        'listing_presentation' => 'Listing presentation',
        'viewing'              => 'Property viewing',
    ];

    /**
     * Resolve an instant slug. Falls back to a prettified version of
     * the slug if no mapping exists (e.g. a brand-new slug not yet in
     * the label map).
     */
    public static function forSlug(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return 'Activity';
        }
        return self::SLUG_LABEL[$slug] ?? self::prettify($slug);
    }

    /**
     * Resolve a calendar event_class. Falls back to the prettified
     * event_class if not in the map.
     */
    public static function forEventClass(?string $eventClass): string
    {
        if ($eventClass === null || $eventClass === '') {
            return 'Calendar event';
        }
        return self::CALENDAR_LABEL[$eventClass] ?? self::prettify($eventClass);
    }

    /**
     * "snake.dotted_keys" -> "Snake dotted keys". Cheap fallback for
     * unseen identifiers.
     */
    private static function prettify(string $key): string
    {
        $clean = str_replace(['.', '_'], ' ', $key);
        return ucfirst(strtolower($clean));
    }
}
