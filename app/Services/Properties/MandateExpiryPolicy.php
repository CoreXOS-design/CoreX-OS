<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\DocumentType;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertyExpiryPopupView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * AT-448 — the ONE place that answers the mandate-expiry questions:
 *
 *   - how many days before expiry does THIS agency warn its agents?
 *   - has THIS agency switched the expiry lock on?
 *   - is THIS listing's expiry date locked right now, and what unlocks it?
 *   - which listings should the Properties-page popup announce to THIS user?
 *
 * Spec: .ai/specs/at448-property-expiry.md §5.2.
 *
 * Every read takes an explicit agency id and resolves `<= 0` (owner outside
 * the switcher, console, queue) to the code defaults WITHOUT writing anything
 * (STANDARDS Rule 17). Never reads PerformanceSetting inline elsewhere.
 */
final class MandateExpiryPolicy
{
    public const SETTING_WARN_DAYS = 'mandate_expiry_warn_days';
    public const SETTING_LOCK      = 'mandate_expiry_lock_enabled';

    public const DEFAULT_WARN_DAYS = 7;
    public const MIN_WARN_DAYS     = 1;
    public const MAX_WARN_DAYS     = 90;

    /** Drive document type whose upload unlocks a locked expiry date. */
    public const EXTENSION_SLUG = 'mandate_extension';

    /** The popup lists at most this many listings; the rest are "+N more — View all". */
    public const POPUP_CAP = 10;

    /** Label shown when the Extension document type row cannot be found. */
    public const EXTENSION_FOLDER_FALLBACK_LABEL = 'Extension';

    /**
     * The Drive folder's real name. The document type is a global, renameable
     * row (Settings → Document Types) and an install may carry an older row
     * with a different label ("Mandate Extension"), so every screen that tells
     * an agent where to upload reads the label from here, never hardcodes it.
     */
    public static function extensionFolderLabel(): string
    {
        $label = DocumentType::query()->where('slug', self::EXTENSION_SLUG)->value('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : self::EXTENSION_FOLDER_FALLBACK_LABEL;
    }

    public static function lockMessage(): string
    {
        return 'Expiry date is locked — this listing is live. Upload the signed extension to the '
            . self::extensionFolderLabel() . ' folder in Drive, then set the new date.';
    }

    // ── Agency settings ───────────────────────────────────────────────────

    public static function warnDaysFor(?int $agencyId): int
    {
        if (($agencyId ?? 0) <= 0) {
            return self::DEFAULT_WARN_DAYS;
        }

        $raw = PerformanceSetting::get(self::SETTING_WARN_DAYS, self::DEFAULT_WARN_DAYS, $agencyId);
        $days = (int) $raw;
        if ($days < self::MIN_WARN_DAYS || $days > self::MAX_WARN_DAYS) {
            return self::DEFAULT_WARN_DAYS;
        }

        return $days;
    }

    public static function lockEnabledFor(?int $agencyId): bool
    {
        if (($agencyId ?? 0) <= 0) {
            return false;
        }

        return (bool) PerformanceSetting::get(self::SETTING_LOCK, 0, $agencyId);
    }

    /** @return array{0: Carbon, 1: Carbon} today … today + warn days (dates, inclusive) */
    public static function expiringWindow(?int $agencyId): array
    {
        $from = Carbon::today();

        return [$from, $from->copy()->addDays(self::warnDaysFor($agencyId))];
    }

    // ── The lock ──────────────────────────────────────────────────────────

    /**
     * Newest non-deleted Extension document on the listing, or null.
     */
    public static function latestExtensionDocumentAt(Property $property): ?Carbon
    {
        if (! $property->exists) {
            return null;
        }

        $typeId = DocumentType::query()->where('slug', self::EXTENSION_SLUG)->value('id');
        if (! $typeId) {
            return null;
        }

        $at = $property->documents()
            ->where('documents.document_type_id', $typeId)
            ->max('documents.created_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * An Extension document uploaded AFTER the last expiry-date change (or any
     * Extension document when the date has never changed) unlocks the date.
     */
    public static function hasFreshExtensionDocument(Property $property): bool
    {
        $uploadedAt = self::latestExtensionDocumentAt($property);
        if ($uploadedAt === null) {
            return false;
        }

        $changedAt = $property->expiry_date_changed_at;

        // Strictly AFTER: the lock must re-engage the instant a new date is saved,
        // and no real user can upload and save within the same second. (Tests
        // separate the two with travel().)
        return $changedAt === null || $uploadedAt->gt($changedAt);
    }

    /**
     * Everything the property page needs to render the lock honestly
     * (STANDARDS "No Silent Locks": say why, offer the way out).
     *
     * @return array{enabled: bool, warn_days: int, gone_live: bool, locked: bool, unlocked_by_extension_at: ?Carbon, days_left: ?int, expired: bool}
     */
    public static function lockState(Property $property): array
    {
        $enabled  = self::lockEnabledFor((int) ($property->agency_id ?? 0));
        $goneLive = $property->exists && $property->hasGoneLive();
        $imported = $property->exists && $property->isImportedStock();
        $hasDate  = $property->expiry_date !== null;

        $extensionAt = ($enabled && $goneLive && ! $imported) ? self::latestExtensionDocumentAt($property) : null;
        $fresh       = $extensionAt !== null
            && ($property->expiry_date_changed_at === null || $extensionAt->gt($property->expiry_date_changed_at));

        $locked = $enabled && $goneLive && $hasDate && ! $imported && ! $fresh;

        $daysLeft = null;
        $expired  = false;
        if ($hasDate && ! $imported) {
            $daysLeft = (int) Carbon::today()->diffInDays($property->expiry_date->copy()->startOfDay(), false);
            $expired  = $daysLeft < 0 || $property->normalizedStatus() === 'expired';
        }

        return [
            'enabled'                  => $enabled,
            'warn_days'                => self::warnDaysFor((int) ($property->agency_id ?? 0)),
            'gone_live'                => $goneLive,
            'locked'                   => $locked,
            'unlocked_by_extension_at' => ($enabled && $goneLive && $hasDate && ! $imported && $fresh) ? $extensionAt : null,
            'days_left'                => $daysLeft,
            'expired'                  => $expired,
        ];
    }

    /**
     * Would saving $posted as the expiry date be a LOCKED change?
     *
     * Only an actual change counts — the same date re-submitted (as the form
     * does on every save) is never a change. Clearing an existing date IS a
     * change. Setting a first date on a listing that has none is allowed
     * (nothing is being extended). Imported Stock is exempt: setting its
     * expiry is the AT-422 takeover gesture.
     */
    public static function isLockedChange(Property $property, mixed $posted): bool
    {
        if (! $property->exists || $property->expiry_date === null) {
            return false;
        }

        $new = ($posted === null || $posted === '') ? null : Carbon::parse((string) $posted)->toDateString();
        if ($new === $property->expiry_date->toDateString()) {
            return false;
        }

        return self::lockState($property)['locked'];
    }

    // ── The popup ─────────────────────────────────────────────────────────

    /**
     * On-market listings that are THIS USER'S OWN — listing agent, second agent,
     * or (for an assistant) their agent's — whose expiry date falls in the
     * agency's warning window AND that this user has not been shown yet (for the
     * expiry date they currently carry). Soonest first.
     *
     * The pop-up is a personal alert ("your mandate is about to run out"), so it
     * is NEVER widened by role: an admin, branch manager or owner is warned about
     * their own listings only, not everyone else's. The role-wide view of the same
     * stock is the "Expiring soon" filter on the Properties list, on demand.
     * (Owner ruling 2026-10-07; replaces spec §2.2's "admin sees the agency".)
     */
    public static function unannouncedExpiringFor(User $user, ?int $agencyId): Builder
    {
        [$from, $to] = self::expiringWindow($agencyId);

        return Property::query()
            ->expiringSoon($from, $to)
            ->excludingImportedOffMarket()
            ->ownListingsFor($user)
            ->whereNotExists(function ($sub) use ($user) {
                $sub->selectRaw('1')
                    ->from('property_expiry_popup_views as pepv')
                    ->whereColumn('pepv.property_id', 'properties.id')
                    ->whereColumn('pepv.expiry_date', 'properties.expiry_date')
                    ->where('pepv.user_id', $user->id);
            })
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /**
     * Record "shown" for the given property ids — ONLY the user's own listings,
     * re-checked here, never trusted from the request. Idempotent.
     *
     * @param  list<int>  $propertyIds
     * @return int rows recorded (new or already present)
     */
    public static function markAnnounced(User $user, ?int $agencyId, array $propertyIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        if ($ids === []) {
            return 0;
        }

        $rows = Property::query()
            ->whereIn('id', $ids)
            ->whereNotNull('expiry_date')
            ->ownListingsFor($user)
            ->get(['id', 'expiry_date']);

        $count = 0;
        foreach ($rows as $property) {
            PropertyExpiryPopupView::query()->firstOrCreate(
                [
                    'user_id'     => $user->id,
                    'property_id' => $property->id,
                    'expiry_date' => $property->expiry_date->toDateString(),
                ],
                [
                    'agency_id' => ($agencyId ?? 0) > 0 ? $agencyId : null,
                    'seen_at'   => now(),
                ]
            );
            $count++;
        }

        return $count;
    }
}
