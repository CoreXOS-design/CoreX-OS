<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Contracts\DomainEvent;
use DateTimeImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for every CoreX domain event.
 *
 * Spec: .ai/specs/corex-domain-events-spec.md Section 3 (E3, E8).
 *
 * Every domain event is a past-tense fact about something that happened in CoreX.
 * Event classes extend this base and add their own typed public properties
 * (the event payload — usually a model + actor + agency_id).
 *
 * Subscribers (listeners) fire either synchronously (default) or queued
 * (implement Illuminate\Contracts\Queue\ShouldQueue).
 *
 * Two cross-cutting concerns are baked in here:
 *   - eventId: a UUID per event emission, for tracing across listeners.
 *   - traceId: a UUID shared across all events in one user-action cascade.
 *     Listeners that emit child events propagate it by passing
 *     $parent->traceId into the child event's constructor.
 *
 * The RecordDomainEvent listener subscribes to this base class (via
 * Event::listen(AbstractDomainEvent::class, ...)) so every concrete subclass
 * is automatically audited.
 */
abstract class AbstractDomainEvent implements DomainEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public readonly string $eventId;
    public readonly DateTimeImmutable $occurredAt;
    public readonly ?string $traceId;

    public function __construct(?string $traceId = null)
    {
        $this->eventId = Uuid::uuid4()->toString();
        $this->occurredAt = new DateTimeImmutable();
        $this->traceId = $traceId;
    }

    /**
     * The fully-qualified class name of this event. Used by the audit listener
     * and by anyone filtering the domain_event_log.
     */
    public function eventName(): string
    {
        return static::class;
    }

    /**
     * Documentation stub — since traceId is readonly, there's no in-place
     * mutation. The actual propagation pattern is:
     *
     *     event(new ChildEvent($payload, $parent->traceId));
     *
     * Build Prompt 08 of the domain-events spec may add a `deriveFrom()`
     * static factory if listener boilerplate justifies it.
     */
    public function withTrace(?string $traceId): static
    {
        return $this;
    }

    /**
     * Get the agency_id for this event. Concrete events override this to
     * return their typed agency_id property. Default null = system event.
     *
     * Used by the audit listener to populate domain_event_log.agency_id.
     */
    public function agencyId(): ?int
    {
        return null;
    }

    /**
     * Get the actor user_id for this event. Concrete events override this
     * to return their typed property or use Auth::id() as default.
     *
     * Used by the audit listener.
     */
    public function actorUserId(): ?int
    {
        return null;
    }

    /**
     * Polymorphic subject (the primary entity the event is about). Concrete
     * events return [class, id] or null.
     *
     * Example: PropertyCreated returns ['App\\Models\\Property', $this->property->id]
     *
     * @return array{0:string,1:int|string}|null
     */
    public function subject(): ?array
    {
        return null;
    }

    /**
     * The event payload as a serialisable array — used by the audit listener
     * to fill domain_event_log.payload_snapshot.
     *
     * Default: serialise all public properties (skipping the framework's
     * own readonly metadata fields, which are stored in dedicated columns).
     * Concrete events may override to redact sensitive data.
     *
     * @return array<string,mixed>
     */
    public function payloadSnapshot(): array
    {
        $reflection = new ReflectionClass($this);
        // Skip framework metadata (event_id/occurred_at/trace_id live in
        // dedicated audit-log columns) and trait properties that aren't part
        // of the domain payload (e.g. InteractsWithSockets::$socket).
        $skip = ['eventId', 'occurredAt', 'traceId', 'socket'];
        $payload = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if (in_array($name, $skip, true)) {
                continue;
            }
            $value = $this->{$name};

            if ($value instanceof Model) {
                $payload[$name] = [
                    'class'      => get_class($value),
                    'id'         => $value->getKey(),
                    'attributes' => $value->toArray(),
                ];
            } elseif ($value instanceof DateTimeImmutable) {
                $payload[$name] = $value->format('Y-m-d\TH:i:s.uP');
            } else {
                $payload[$name] = $value;
            }
        }
        return $payload;
    }

    /**
     * Domain-specific context (free-form). Concrete events may override.
     * Default empty.
     *
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return [];
    }
}
