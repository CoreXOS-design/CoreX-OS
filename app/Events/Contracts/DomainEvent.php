<?php

declare(strict_types=1);

namespace App\Events\Contracts;

use DateTimeImmutable;

/**
 * Marker + contract for every CoreX domain event.
 *
 * Spec: .ai/specs/corex-domain-events-spec.md Section 3 (E3, E8).
 *
 * The wildcard audit listener (RecordDomainEvent) subscribes to this
 * interface, not to AbstractDomainEvent (the concrete base class), because
 * Laravel's event dispatcher resolves listeners by interface — not by
 * parent class. Subclasses of AbstractDomainEvent automatically implement
 * this interface and the audit listener fires for all of them.
 */
interface DomainEvent
{
    public function eventName(): string;
    public function agencyId(): ?int;
    public function actorUserId(): ?int;

    /** @return array{0:string,1:int|string}|null */
    public function subject(): ?array;

    /** @return array<string,mixed> */
    public function payloadSnapshot(): array;

    /** @return array<string,mixed> */
    public function context(): array;
}
