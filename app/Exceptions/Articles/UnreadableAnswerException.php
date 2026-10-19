<?php

declare(strict_types=1);

namespace App\Exceptions\Articles;

use RuntimeException;

/**
 * The AI answered (and was paid for) but the answer could not be read as the JSON asked for.
 * Distinct from a transient API failure so the Article Library can count it per item and stop
 * paying for the same stuck item every run (AT-456 audit D-L2).
 */
final class UnreadableAnswerException extends RuntimeException
{
}
