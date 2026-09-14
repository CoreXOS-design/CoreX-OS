<?php

declare(strict_types=1);

namespace App\Services\Property;

use App\Models\ContactProperty;

/**
 * What ContactPropertyLinker::link() actually did, so a caller can decide
 * whether to fire ContactLinkedToProperty (only on $isNew, matching the
 * "no-op re-attach" rule every existing caller already follows) and
 * whether to write an audit entry for a role change ($roleChanged or
 * $wasRestored — both mean the contact's relationship to this property is
 * different now than it was, which per Johan's ruling is a real business
 * event, not a silent field update).
 */
final class ContactPropertyLinkResult
{
    public function __construct(
        public readonly ContactProperty $row,
        public readonly bool $isNew,
        public readonly bool $wasRestored,
        public readonly bool $roleChanged,
        public readonly ?string $previousRole,
    ) {
    }
}
