<?php

declare(strict_types=1);

namespace App\Exceptions\Deal;

use App\Models\Deal;
use RuntimeException;

/**
 * DR2 Wave 2 — thrown when granting a deal would violate the "at most ONE
 * granted (or registered) deal per property" constraint. Carries the deal that
 * already holds the committed lane so callers can surface Johan's block modal
 * (clickable "deal #XXXX" → new tab) and preserve the user's entered data.
 */
final class DuplicateGrantException extends RuntimeException
{
    public function __construct(public readonly Deal $existingGrantedDeal)
    {
        parent::__construct(sprintf(
            'Deal #%s already carries a %s status on this property.',
            (string) ($existingGrantedDeal->deal_no ?? $existingGrantedDeal->id),
            $existingGrantedDeal->accepted_status === 'R' ? 'Registered' : 'Granted',
        ));
    }

    /** Human status word of the blocking deal, for the UX copy. */
    public function existingStatusLabel(): string
    {
        return $this->existingGrantedDeal->accepted_status === 'R' ? 'Registered' : 'Granted';
    }
}
