<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\PropertySettingItem;

/**
 * Single source of truth for "what title_type is this property?".
 *
 * Before this service existed the heuristic was duplicated twice
 * (MicSnapshotHydrator + PresentationReviewController) and the
 * subject classifier read only category → property_setting_items
 * mapping, which fails on agencies whose portfolio mixes title types
 * under one category (e.g. HFC's "Residential" covers 72 sectional
 * units + 24 vacant land subjects + 174 houses).
 *
 * Resolution order (per Phase A* keystone plan):
 *   1. property.property_type via the canonical heuristic
 *      ("Sectional Title"/"Apartment"/"Flat"/"Unit"/"Townhouse"/"Duplex"
 *       → sectional_title; "Vacant Land"/"Plot"/"Stand"/"Erf" → vacant_land;
 *       else full_title).
 *   2. property.category → property_setting_items.title_type (agency-scoped
 *      with null-agency system-defaults fallback). This is the safety net
 *      for rows where property_type is somehow blank.
 *   3. null — caller decides what to do.
 *
 * The TitleTypeClassifier::TITLE_* constants delegate to
 * PropertySettingItem so the existing constants stay canonical.
 */
final class TitleTypeClassifier
{
    public const TITLE_FULL      = PropertySettingItem::TITLE_FULL;
    public const TITLE_SECTIONAL = PropertySettingItem::TITLE_SECTIONAL;
    public const TITLE_VACANT    = PropertySettingItem::TITLE_VACANT;
    public const TITLE_OTHER     = PropertySettingItem::TITLE_OTHER;

    /**
     * Code-gate hardening (Uvonique bug, 2026-08) — the ONE keyword taxonomy.
     * Before this, CompPoolBuilder::kind() and CompetitorStockMatchService::
     * normalizeTypeKind() each kept their OWN copy of the "finer kind"
     * keywords (apartment/townhouse buckets), and neither copy agreed with
     * this class's coarser sectional/freehold keywords — Penthouse, Studio,
     * Simplex, Cluster and Maisonette had no match here at all and silently
     * fell through to the TITLE_FULL default (freehold), while the other two
     * classifiers correctly bucketed them as apartment/townhouse-kind. A
     * subject or comp with one of these property_type strings therefore
     * disagreed with itself across the codebase. These constants are now the
     * single source every classifier (this one, CompPoolBuilder::kind(),
     * CompetitorStockMatchService::normalizeTypeKind()) reads from, so they
     * can never drift apart again.
     */
    public const APARTMENT_KEYWORDS = ['sectional', 'apartment', 'flat', 'unit', 'penthouse', 'studio'];
    public const TOWNHOUSE_KEYWORDS = ['townhouse', 'duplex', 'simplex', 'cluster', 'maisonette'];
    public const VACANT_KEYWORDS    = ['vacant', 'plot', 'stand', 'erf'];

    /**
     * Canonical heuristic — classify a free-text property_type string.
     *
     * Lifted verbatim from the duplicate bodies at
     * MicSnapshotHydrator::classifyCompTitleType (L312-325) and
     * PresentationReviewController::classifyCompTitleType (L584-597).
     * Both duplicates are deleted in this build.
     */
    public function fromPropertyType(?string $rawType): ?string
    {
        $t = strtolower((string) $rawType);
        if ($t === '') {
            return null;
        }
        foreach (array_merge(self::APARTMENT_KEYWORDS, self::TOWNHOUSE_KEYWORDS) as $kw) {
            if (str_contains($t, $kw)) {
                return self::TITLE_SECTIONAL;
            }
        }
        foreach (self::VACANT_KEYWORDS as $kw) {
            if (str_contains($t, $kw)) {
                return self::TITLE_VACANT;
            }
        }
        if ($t === 'land') {
            // Bare "Land" — appeared in prospecting_listings as 2 rows that
            // were silently classified as full_title by the keyword default.
            // It's vacant land in every observed portal context. Equality
            // (not str_contains) so we don't swallow "Vacant Land" or any
            // other phrase that already matched above.
            return self::TITLE_VACANT;
        }
        return self::TITLE_FULL;
    }

    /**
     * Code-gate hardening — the SAME "signal overrides generic text"
     * classification MicSnapshotHydrator::deriveCompTitleType() used to keep
     * privately to itself, now shared so every comp-classifying caller
     * (MicSnapshotHydrator, AnalysisDataService's render-layer hard filter,
     * and any future one) agrees. A populated scheme name or section number
     * is an unambiguous sectional-title signal (a unit in a sectional
     * scheme) that overrides the generic source property_type, which for
     * portal/CMA-import data is often just "Residence"/"Residential" and
     * cannot itself distinguish freehold from sectional.
     */
    public function categoryForComp(?string $propertyType, ?string $schemeName = null, ?string $sectionNumber = null): ?string
    {
        if (trim((string) $schemeName) !== '' || trim((string) $sectionNumber) !== '') {
            return self::TITLE_SECTIONAL;
        }
        return $this->fromPropertyType($propertyType);
    }

    /**
     * Category fallback — read title_type from the agency's
     * property_setting_items category row. Falls back to system defaults
     * (null agency_id) when the agency hasn't customised its category
     * list yet. Lifted from MicSnapshotHydrator::resolveSubjectTitleType
     * L262-300.
     */
    public function fromCategory(int $agencyId, ?string $categoryName): ?string
    {
        if (!is_string($categoryName) || trim($categoryName) === '') {
            return null;
        }

        $row = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('group', PropertySettingItem::GROUP_CATEGORY)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($categoryName))])
            ->first(['title_type']);

        if (!$row) {
            $row = PropertySettingItem::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('group', PropertySettingItem::GROUP_CATEGORY)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($categoryName))])
                ->first(['title_type']);
        }

        return $row && !empty($row->title_type) ? (string) $row->title_type : null;
    }

    /**
     * Build 7 — return the agency's configured display label for a
     * raw category string. The raw `properties.category` column tends
     * to be lowercase ("residential"); the agency's
     * property_setting_items row name is proper-case ("Residential").
     * Match case-insensitively + return the agency's casing. Falls
     * back to Str::title() on the raw input when no matching agency
     * row exists. Returns null when input itself is blank.
     */
    public function displayCategoryLabel(int $agencyId, ?string $rawCategoryName): ?string
    {
        if (!is_string($rawCategoryName) || trim($rawCategoryName) === '') {
            return null;
        }

        $row = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('group', PropertySettingItem::GROUP_CATEGORY)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($rawCategoryName))])
            ->first(['name']);

        if (!$row) {
            $row = PropertySettingItem::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('group', PropertySettingItem::GROUP_CATEGORY)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($rawCategoryName))])
                ->first(['name']);
        }

        return $row && !empty($row->name)
            ? (string) $row->name
            : \Illuminate\Support\Str::title(trim($rawCategoryName));
    }

    /**
     * Resolve a property's title_type using property_type first, falling
     * back to category-driven mapping when property_type is blank.
     *
     * Used by PropertyObserver::saving() to populate properties.title_type
     * and by the presentation pipeline as a safety net when reading a
     * row that pre-dates the backfill (NULL column).
     */
    public function forProperty(Property $property): ?string
    {
        $fromType = $this->fromPropertyType($property->property_type);
        if ($fromType !== null) {
            return $fromType;
        }
        if ($property->agency_id) {
            return $this->fromCategory((int) $property->agency_id, $property->category);
        }
        return null;
    }
}
