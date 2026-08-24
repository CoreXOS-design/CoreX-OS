<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Billing\AgentSeatRelease;
use RuntimeException;

/**
 * Thrown when someone tries to put a released agent back into a billable seat
 * before the 30-day hold has run.
 *
 * AT-278. Spec: .ai/specs/agent-seat-release-lock.md §6.2
 *
 * EXPECTED and user-facing — controllers map it to a validation error, never a
 * 500. The message must always carry the unlock DATE and the way forward
 * (STANDARDS: "No Silent Locks — read-only states must say why and offer the
 * action that unlocks them").
 */
class SeatReinstatementLockedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?AgentSeatRelease $release = null,
    ) {
        parent::__construct($message);
    }

    public static function for(string $name, AgentSeatRelease $release): self
    {
        $days = $release->remainingDays();

        return new self(
            sprintf(
                '%s cannot be reinstated until %s (%s). They were released on %s and cannot be '
                . 'added back for %d days. '
                . 'If this was a mistake, contact CoreX support — CoreX Dev can lift the hold immediately.',
                $name,
                $release->reinstatable_at->format('j F Y'),
                $days === 1 ? '1 day' : "{$days} days",
                $release->released_at->format('j F Y'),
                (int) $release->released_at->diffInDays($release->reinstatable_at),
            ),
            $release,
        );
    }

    /** A System Owner reached for the override without saying why. */
    public static function overrideNeedsReason(): self
    {
        return new self(
            'Give a reason for lifting the hold (at least 10 characters). '
            . 'Overrides are recorded permanently against the agency.'
        );
    }

    /** Someone who is not CoreX Dev tried to override. */
    public static function notAuthorised(): self
    {
        return new self(
            'Only CoreX Dev can lift a hold early. '
            . 'Contact CoreX support if this agent was archived by mistake.'
        );
    }
}
