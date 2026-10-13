<?php

namespace App\Services\RoadToSold;

use App\Models\User;

/**
 * AT-454 (lane 3) — the one place lane 3 reads Road to Sold agency settings.
 *
 * Lane 1 owns RoadToSold\SettingsService (typed getters over rts_settings). Until that class lands
 * on this branch, this bridge answers with the documented defaults (spec §2.1); once it exists it
 * delegates, so nothing in lane 3 changes. Never writes. agency_id <= 0 answers defaults.
 */
final class SettingsBridge
{
    private function __construct(private readonly ?object $svc)
    {
    }

    public static function forUser(User $user, string $track = 'sales'): self
    {
        return self::forAgency((int) ($user->effectiveAgencyId() ?: 0), $track);
    }

    public static function forAgency(int $agencyId, string $track = 'sales'): self
    {
        if ($agencyId > 0 && class_exists(SettingsService::class)) {
            return new self(SettingsService::forAgency($agencyId, $track));
        }

        return new self(null);
    }

    public function noAnswerDropAfter(): int
    {
        return $this->svc ? (int) $this->svc->noAnswerDropAfter() : 3;
    }

    public function holdDays(): int
    {
        return $this->svc ? (int) $this->svc->holdDays() : 30;
    }

    public function summaryGreenFromPct(): int
    {
        return $this->svc ? (int) $this->svc->summaryGreenFromPct() : 90;
    }

    public function summaryRedBelowPct(): int
    {
        return $this->svc ? (int) $this->svc->summaryRedBelowPct() : 70;
    }

    public function statusForPct(float $pct): string
    {
        if ($this->svc) {
            return (string) $this->svc->statusForPct($pct);
        }

        return $pct >= $this->summaryGreenFromPct() ? 'green' : ($pct < $this->summaryRedBelowPct() ? 'red' : 'amber');
    }

    public function missedItemsMode(): string
    {
        return $this->svc ? (string) $this->svc->missedItemsMode() : 'show_missed';
    }

    /** @return array<int, array{key:string,label:string,enabled:bool}> */
    public function summarySteps(): array
    {
        if ($this->svc) {
            return $this->svc->summarySteps();
        }

        $labels = [
            'calls_made'        => 'Calls made',
            'conversations'     => 'Conversations',
            'valuations_booked' => 'Valuations booked',
            'valuations_done'   => 'Valuations done',
            'mandates_signed'   => 'Mandates signed',
            'listings_live'     => 'Listings live',
        ];

        return array_values(array_map(
            fn ($key, $label) => ['key' => $key, 'label' => $label, 'enabled' => true],
            array_keys($labels),
            $labels,
        ));
    }
}
