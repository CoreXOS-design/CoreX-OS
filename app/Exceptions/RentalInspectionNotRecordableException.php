<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Audit H1/H2 — thrown when a write (observation, photo, signature, notes,
 * complete, cancel...) is attempted against an inspection whose status does
 * not allow it (completed, cancelled, archived). A LogicException so every
 * existing `catch (\LogicException)` -> 409 handler in the module treats it
 * the same way.
 */
class RentalInspectionNotRecordableException extends \LogicException
{
}
