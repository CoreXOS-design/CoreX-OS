<?php

declare(strict_types=1);

namespace App\Exceptions\CoreMatches;

use Exception;

/**
 * AT-Core-Matches, Johan's ruling 1 — "only a branch manager or admin can
 * move a buyer between agents. Ever. An agent can never reassign a buyer,
 * including to themselves, by any route." Thrown by
 * ContactMatch::reassignTo() when the acting user lacks core_matches.reassign
 * — the server-side gate, independent of whether a button was ever shown.
 */
class ReassignmentNotAuthorizedException extends Exception
{
}
