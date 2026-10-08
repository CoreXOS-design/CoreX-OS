<?php

namespace App\Services\Properties;

use App\Models\Property;

/**
 * .ai/specs/other-agency-stock.md §5c — what each action on the property page's
 * Actions panel does to an Other Agency Stock (OAS) property. ONE registry, so the
 * answer is written down once and every layer (button, route, service) reads it:
 *
 *  - BLOCKED  — greyed in the UI with a plain hover reason AND refused server-side.
 *               Rules of their own: they do NOT lean on the marketing-compliance
 *               gate (which greys some of these by accident, and only while the
 *               listing is not "marketable").
 *  - ALLOWED  — works exactly as on any property.
 *  - EXISTING — already handled by its own older guard (syndication layers, §2;
 *               duplicate, §5b); listed here so nothing on the panel is undeclared.
 *
 * Johan, 2026-10-07: the consent an agent ticks on an OAS import is ONLY permission
 * to take a buyer to the property — not to market, pitch or present it. OAS is used
 * only in Core Matches and viewing packs / share links.
 *
 * The block follows the STATUS, nothing else (Property::isOtherAgencyStock()): when
 * an authorised user moves the property away from other_agency_stock it becomes
 * ordinary agency stock and every block here lifts with no further step.
 *
 * GUARD: tests/Feature/Properties/OtherAgencyStockActionRulesTest.php fails when a
 * button on the Actions panel carries no `data-oas-action` naming a key below, so a
 * new action cannot ship without declaring its OAS behaviour here.
 */
final class OtherAgencyStockActionRules
{
    public const BLOCKED  = 'blocked';
    public const ALLOWED  = 'allowed';
    public const EXISTING = 'existing';

    /** Shared tail of every block reason — the one thing the import consent covers. */
    private const WHY = "It's another agency's listing, and the permission you confirmed on import only covers taking a buyer to see it.";

    /**
     * action key => [mode, reason|null]. The key is the `data-oas-action` value on the
     * button. Reasons are shown to agents verbatim (hover text and refusal message).
     */
    public const RULES = [
        // Panel buttons -----------------------------------------------------------
        'syndication'            => [self::EXISTING, null],   // refused by every portal layer, §2
        'live_preview'           => [self::ALLOWED,  null],
        'ad_builder'             => [self::BLOCKED,  "Other Agency Stock can't be advertised. " . self::WHY],
        'market_property'        => [self::BLOCKED,  "Other Agency Stock can't be marketed on social media. " . self::WHY],
        'pitch_seller'           => [self::BLOCKED,  "Other Agency Stock can't be pitched to a seller. " . self::WHY],
        'share_listing'          => [self::ALLOWED,  null],   // taking a buyer to the property is exactly what it is for
        'generate_presentation'  => [self::BLOCKED,  "Other Agency Stock can't be presented. " . self::WHY],
        'duplicate'              => [self::EXISTING, null],   // §5b
        'archive'                => [self::ALLOWED,  null],
        'report_non_compliance'  => [self::ALLOWED,  null],

        // Not on the Actions panel -------------------------------------------------
        // Standalone brochure (`corex.properties.brochure`, the printable ad sheet). DECIDED 2026-10-08
        // (Johan): ALLOWED — an agent who pulled the stock may print a brochure for their buyer. A
        // declared decision, not a gap: the route calls no assertAllowed() on purpose. (The Ad Builder
        // page and the Ad Manager tool that also link to it stay blocked via 'ad_builder'.)
        'brochure'               => [self::ALLOWED,  null],
    ];

    /**
     * Keys in RULES that are NOT a button on the Actions panel (a route or service with no panel
     * button of its own). The guard test expects every OTHER key to belong to a real panel button.
     */
    public const NOT_ON_PANEL = ['brochure'];

    public static function keys(): array
    {
        return array_keys(self::RULES);
    }

    public static function mode(string $action): string
    {
        if (! isset(self::RULES[$action])) {
            // Fail loud: an undeclared action is a bug, never a silent "allowed".
            throw new \InvalidArgumentException("Action '{$action}' has no Other Agency Stock rule — declare it in " . self::class . '::RULES.');
        }

        return self::RULES[$action][0];
    }

    public static function reason(string $action): string
    {
        self::mode($action);

        return (string) (self::RULES[$action][1] ?? '');
    }

    /** True when $action is refused for this property right now (OAS status + a BLOCKED rule). */
    public static function isBlocked(string $action, ?Property $property): bool
    {
        return $property !== null
            && $property->isOtherAgencyStock()
            && self::mode($action) === self::BLOCKED;
    }

    /**
     * Of these property ids, the ones for which $action is refused right now (OAS status + a BLOCKED rule).
     * ONE query for a whole list/row set — screens that show a link to the action for many properties
     * (Market Intelligence, the prospecting list) use this to HIDE the link rather than show one that refuses.
     *
     * @param  iterable<int|string|null>  $propertyIds
     * @return array<int,true>  property id => true
     */
    public static function blockedPropertyIds(string $action, iterable $propertyIds): array
    {
        if (self::mode($action) !== self::BLOCKED) {
            return [];
        }

        $ids = [];
        foreach ($propertyIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        if (! $ids) {
            return [];
        }

        $blocked = [];
        foreach (Property::withoutGlobalScopes()
            ->whereIn('id', array_keys($ids))
            ->where('status', Property::STATUS_OTHER_AGENCY_STOCK)
            ->pluck('id') as $id) {
            $blocked[(int) $id] = true;
        }

        return $blocked;
    }

    /**
     * Server-side enforcement. Call first in every route/service that performs $action
     * for a property — hiding a button is never the fix.
     *
     * @throws OtherAgencyStockActionBlockedException (403; renders as JSON or a redirect back to the property)
     */
    public static function assertAllowed(string $action, ?Property $property): void
    {
        if (self::isBlocked($action, $property)) {
            throw new OtherAgencyStockActionBlockedException($action, (int) $property->id, self::reason($action));
        }
    }
}
