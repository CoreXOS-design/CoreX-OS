<?php

namespace App\Services\Properties;

use App\Models\Property;
use App\Models\User;

/**
 * .ai/specs/other-agency-stock.md §8/§8a (Johan) — "the stock is used
 * exactly as the advert is." Once a property is (or was, in this same
 * write) Other Agency Stock, its imported advert content is READ-ONLY in
 * CoreX UNLESS the acting user is authorised (edits directly, always) or is
 * the property's own agent AND the property is currently unlocked (an
 * authorised user approved a request — see OtherAgencyStockUnlock). A
 * re-import (OtherAgencyStockImportService, which sets
 * Property::$allowOtherAgencyStockContentWrite) always bypasses this and
 * also re-locks + records a fresh consent row.
 *
 * Still allowed while other_agency_stock and LOCKED: internal notes,
 * matching/viewing-pack use, the status change itself (via
 * OtherAgencyStockStatusGate), and archiving (soft delete) — none of those
 * touch a LOCKED_FIELD, so they are never blocked by this.
 */
class OtherAgencyStockContentLock
{
    public const LOCKED_FIELDS = [
        // The advert's own content.
        'description', 'title', 'headline',
        'price', 'price_on_application',
        'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
        'property_type', 'listing_type',
        'features_json', 'spaces_json',
        // Photos.
        'images_json', 'gallery_images_json',
        'dawn_images_json', 'noon_images_json', 'dusk_images_json',
        // Address / geo — the PORTAL'S OWN advertised location only.
        // 2026-09-30 — street_number/street_name/erf_number deliberately
        // removed: those are INTERNAL fields the agent fills in for their
        // own records (unit/complex/erf detail the portal ad never showed,
        // needed for FICA/compliance/deeds work) — never part of "the
        // advert", so they must stay editable even while locked.
        // complex_name/unit_number/property_number/stand_number/
        // unit_section_block were never in this list either (same
        // reasoning) — this is a correction, not a new exemption class.
        'suburb', 'city', 'province', 'address', 'latitude', 'longitude',
    ];

    /**
     * The locked fields $property is currently dirty on, given its ORIGINAL
     * (pre-save) status — so a write that simultaneously moves status AWAY
     * FROM other_agency_stock cannot smuggle a content edit through in the
     * same request. Empty array = nothing locked is being touched (or the
     * acting user is entitled to edit it right now), safe to save.
     *
     * $actor defaults to auth()->user() — a null/system actor (queued job,
     * console command) can NEVER pass the agent-while-unlocked check (there's
     * no user to prove is the agent) and is refused UNLESS
     * allowOtherAgencyStockContentWrite is set, exactly like a re-import.
     *
     * @return string[]
     */
    public static function violatingFields(Property $property, ?User $actor = null): array
    {
        if ($property->allowOtherAgencyStockContentWrite) {
            return [];
        }

        $originalStatus = strtolower(trim((string) $property->getOriginal('status')));
        if ($originalStatus !== Property::STATUS_OTHER_AGENCY_STOCK) {
            return [];
        }

        $dirtyLocked = array_values(array_intersect(array_keys($property->getDirty()), self::LOCKED_FIELDS));
        if (empty($dirtyLocked)) {
            return [];
        }

        $actor = $actor ?? auth()->user();
        if ($actor && self::actorMayEditAdvertContent($actor, $property)) {
            return [];
        }

        return $dirtyLocked;
    }

    /**
     * Authorised users edit directly, always. The property's own agent may
     * edit only while an authorised user has approved an unlock request AND
     * no re-lock/re-import has happened since (OtherAgencyStockUnlock's
     * latest-event-wins state). Any OTHER agent is refused even while
     * unlocked — the unlock is scoped to this property's agent specifically.
     */
    public static function actorMayEditAdvertContent(User $actor, Property $property): bool
    {
        if (OtherAgencyStockStatusGate::canAuthoriseAdvertEdit($actor)) {
            return true;
        }

        if ((int) $actor->id !== (int) $property->agent_id) {
            return false;
        }

        return \App\Models\OtherAgencyStockUnlock::currentStateFor($property)['state'] === 'unlocked';
    }
}
