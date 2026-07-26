<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Fires when an agency turns a feature on or off (spec: corex-feature-registry.md §3.8).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 *
 * When it fires:
 *   FeatureSettingsController@update (Phase 5) after an ACTUAL state change for a
 *   feature key — never on a no-op save.
 *
 * Payload is SCALARS ONLY (agencyId, featureKey, enabled, changedByUserId) — no
 * models — so any sync listener stays cheap and a Job carrying it never
 * ModelNotFound-s on rehydration. Per memory [[event_listener_double_registration]],
 * event auto-discovery is OFF: a listener must be explicitly registered in
 * AppServiceProvider::boot(), and a QUEUED listener on a domain event fatals
 * (AbstractDomainEvent's readonly $eventId can't be restored in the child scope) —
 * so keep the listener SYNC and dispatch a Job for any heavy work.
 *
 * Typical subscribers:
 *   - (cache-bust of AgencyFeatureService's request memo — trivial, sync)
 *   - Audit\RecordDomainEvent (wildcard)
 */
final class AgencyFeatureToggled extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly string $featureKey,
        public readonly bool $enabled,
        public readonly ?int $changedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->changedByUserId; }
}
