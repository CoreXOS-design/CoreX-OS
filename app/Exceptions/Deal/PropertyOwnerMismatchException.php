<?php

declare(strict_types=1);

namespace App\Exceptions\Deal;

use Exception;

/**
 * AT-398 — thrown by DealPropertyOwnerGate when a property cannot be added
 * to (or a deal cannot be saved with) a given property, because either the
 * property's owners aren't known, or they don't exactly match the owner set
 * already established on the deal. Always carries a plain-English message —
 * Johan's users are estate agents, not developers.
 */
class PropertyOwnerMismatchException extends Exception
{
}
