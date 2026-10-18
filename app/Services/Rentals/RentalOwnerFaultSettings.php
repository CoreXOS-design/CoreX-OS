<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\RentalPropertyFaultSetting as Row;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.2 - THE one place the fault flow reads what the OWNER allows their agent to do without asking.
 * Read-only; sends nothing. The names below are a contract with the fault flow - do not rename them.
 *
 *   RentalOwnerFaultSettings::for($property)->repairLimit()          ?float  null = MUST ASK THE OWNER
 *   ...->emergencyLimit()                                             ?float  null = must ask
 *   ...->calloutAllowedUpTo()                                         ?float  null = must ask (the owner said No, or nothing counts yet)
 *   ...->firstResponders($faultType)                                  Collection (cc5's "Who looks at faults first")
 *   ...->status()                                                     confirmed_by_owner | captured_by_agent | disputed | not_set
 *
 * Only `confirmed_by_owner` and `captured_by_agent` are standing authority. `not_set` (nothing recorded - the agency's defaults are only
 * proposals) and `disputed` (the owner pressed "I do not agree") answer "must ask" for everything. A stored R0 is a real value.
 */
final class RentalOwnerFaultSettings
{
    private function __construct(private Property $property, private ?Row $row)
    {
    }

    public static function for(Property $property): self
    {
        return new self($property, Row::withoutGlobalScopes()->where('property_id', $property->id)->first());
    }

    public function status(): string
    {
        return $this->row?->status ?: Row::STATUS_NOT_SET;
    }

    public function hasStandingAuthority(): bool
    {
        return in_array($this->status(), [Row::STATUS_CONFIRMED, Row::STATUS_CAPTURED], true);
    }

    /** Repairs the agent may authorise without asking, in rand; null = must ask. */
    public function repairLimit(): ?float
    {
        return $this->hasStandingAuthority() && $this->row->repair_limit !== null ? (float) $this->row->repair_limit : null;
    }

    /**
     * The ONE number the existing approval gate / job card / fault screens use for "work up to R x needs no owner approval" (section 22.7). Once the owner's
     * own settings have an answer (anything but not_set) THAT is the limit - and "must ask" is R0, so a disputed property sends every job to the owner.
     * Returns null while there is no answer yet, and the caller keeps its old fallback (the agency default) until the fault flow is moved onto repairLimit().
     */
    public static function limitInForce(Property $property): ?float
    {
        $r = self::for($property);

        return $r->row() && $r->status() !== Row::STATUS_NOT_SET ? ($r->repairLimit() ?? 0.0) : null;
    }

    /** Emergency repairs the agent may authorise without asking, in rand; null = must ask. */
    public function emergencyLimit(): ?float
    {
        return $this->hasStandingAuthority() && $this->row->emergency_limit !== null ? (float) $this->row->emergency_limit : null;
    }

    /** A contractor who charges a call-out fee may be sent without asking up to this amount; null = must ask (No, no answer, or nothing counts yet). */
    public function calloutAllowedUpTo(): ?float
    {
        return $this->hasStandingAuthority() && $this->row->callout_mode === 'yes' && $this->row->callout_limit !== null ? (float) $this->row->callout_limit : null;
    }

    /** cc5's "Who looks at faults first" for this property (and fault type), read-only. */
    public function firstResponders(RentalFaultType|int|null $faultType = null): Collection
    {
        return $this->property->firstRespondersFor($faultType);
    }

    /** What is STORED, whatever the status - for screens (never for decisions). */
    public function values(): array
    {
        return [
            'repair_limit' => $this->row?->repair_limit !== null ? (float) $this->row->repair_limit : null,
            'emergency_limit' => $this->row?->emergency_limit !== null ? (float) $this->row->emergency_limit : null,
            'callout_mode' => $this->row?->callout_mode,
            'callout_limit' => $this->row?->callout_limit !== null ? (float) $this->row->callout_limit : null,
        ];
    }

    /** The agency's defaults, only ever PROPOSALS that pre-fill a screen; nothing here is authority. */
    public function proposed(): array
    {
        return [
            'repair_limit' => RentalWorkOrderSetting::spendThresholdFor((int) $this->property->agency_id),
            'emergency_limit' => null,
            'callout_mode' => null,
            'callout_limit' => null,
        ];
    }

    public function row(): ?Row
    {
        return $this->row;
    }

    public function version(): int
    {
        return (int) ($this->row?->version ?? 0);
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            Row::STATUS_CONFIRMED => 'Confirmed by owner',
            Row::STATUS_CAPTURED => 'Captured by your agent - not confirmed yet',
            Row::STATUS_DISPUTED => 'Owner does not agree',
            default => 'Not set yet',
        };
    }

    public function capturedBy(): ?string
    {
        return $this->row?->capturedBy?->name;
    }

    public function confirmedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->row?->confirmed_at;
    }
}
