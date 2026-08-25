<?php

namespace App\Exceptions;

use App\Models\Contact;
use Exception;

/**
 * A representative chain (Contact::representatives(), which has no
 * contact_kind filter — any party can be represented by an entity, which
 * can itself be represented by someone else) failed to resolve to a real
 * signer. Johan, 2026-08-25: "the signer is always a natural person... if a
 * chain terminates on an entity with no natural person, that is a state the
 * system must refuse, not render." Three distinct refusals, each named so a
 * failure is diagnosable rather than a generic "something went wrong":
 *
 *  - tooDeep()                    — a chain longer than a real SA
 *                                    conveyancing document has ever needed.
 *  - cycleDetected()               — A represents B represents A (or any
 *                                    longer loop) — would recurse forever.
 *  - entityWithNoRepresentative() — a nested entity representative has no
 *                                    representative of its own, so the
 *                                    chain has no natural person to sign or
 *                                    to name.
 *
 * Blocks document composition with a message naming the specific party,
 * rather than silently truncating the chain or rendering a bare company
 * name where a person's identity belongs (exactly the bug this exception
 * exists to prevent from recurring).
 */
class UnresolvableRepresentativeChainException extends Exception
{
    public static function tooDeep(Contact $party, int $maxDepth): self
    {
        $name = (string) ($party->entity_name ?: $party->full_name);

        return new self(
            "Representative chain for \"{$name}\" is deeper than {$maxDepth} levels — check for a data-entry mistake (a representative linked to the wrong entity) before re-sending.",
            0,
            null
        );
    }

    public static function cycleDetected(Contact $party, Contact $repeated): self
    {
        $name = (string) ($party->entity_name ?: $party->full_name);
        $repeatedName = (string) ($repeated->entity_name ?: $repeated->full_name);

        return new self(
            "Representative chain for \"{$name}\" loops back to \"{$repeatedName}\" — a representative link points back at an entity already earlier in its own chain. Fix the representative links before re-sending.",
            0,
            null
        );
    }

    public static function entityWithNoRepresentative(Contact $entity): self
    {
        $name = (string) ($entity->entity_name ?: $entity->full_name);

        return new self(
            "\"{$name}\" is a representative in this chain but is itself a company/entity with no representative of its own linked — a document cannot be signed or named without a natural person at the end of every chain. Link a representative for \"{$name}\" before re-sending.",
            0,
            null
        );
    }
}
