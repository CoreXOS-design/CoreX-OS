<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * The AI could not produce a usable template (service trouble, or its answer failed
 * validation twice). Not counted against the agency's monthly allowance. The message is
 * safe to show the user as-is.
 */
final class AiTemplateGenerationFailedException extends RuntimeException
{
}
