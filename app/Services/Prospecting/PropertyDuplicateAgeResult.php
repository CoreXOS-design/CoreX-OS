<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * The result of PropertyDuplicateAgeResolver::resolve() — everything an agent, BM,
 * or admin needs to see to decide whether a deeds-capture duplicate match may be
 * taken. Never present a guessed age as a known one — $isFallback tells the caller
 * (and the screen) exactly that.
 */
final class PropertyDuplicateAgeResult
{
    public const BAND_ACTIVE_BLOCKED = 'active_blocked';
    public const BAND_NO_GO = 'no_go';
    public const BAND_NEEDS_APPROVAL = 'needs_approval';
    public const BAND_AUTO_TAKE = 'auto_take';

    public function __construct(
        /** Whole calendar days since $dateField, or null when the property is on-market (no clock applies). */
        public readonly ?int $days,
        /** Which column/signal the count came from: 'last_human_touch'|'status_changed_at'|'expiry_date'|'created_at'|null. */
        public readonly ?string $dateField,
        /** True when $dateField is a fallback (the primary signal for this status was unavailable). */
        public readonly bool $isFallback,
        public readonly string $band,
        /**
         * Every independent reason a hard block fired — e.g. ['Currently active',
         * 'Mandate runs to 16 Aug 2027'] when both apply (2 Venice Drive, 2026-08-21).
         * Empty for every band except BAND_ACTIVE_BLOCKED, which is never returned
         * without at least one reason.
         *
         * @var array<int, string>
         */
        public readonly array $blockReasons = [],
    ) {}

    public function actionLabel(): string
    {
        return match ($this->band) {
            self::BAND_ACTIVE_BLOCKED => 'Blocked — live stock',
            self::BAND_NO_GO => 'No go',
            self::BAND_NEEDS_APPROVAL => 'Needs approval',
            self::BAND_AUTO_TAKE => 'Take it',
            default => $this->band,
        };
    }

    public function dateFieldLabel(): ?string
    {
        return match ($this->dateField) {
            'status_changed_at' => 'when the status last changed',
            'expiry_date' => 'mandate expiry date',
            'created_at' => 'when the record was created',
            default => null,
        };
    }
}
