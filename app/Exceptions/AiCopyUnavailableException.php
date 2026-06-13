<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown for EXPECTED, user-facing AI-copy states (no API key on this
 * environment, AI disabled, agency budget reached). The controller maps
 * these to a clean 422 with the message shown directly to the user — as
 * opposed to an unexpected upstream/parse failure (generic 500).
 *
 * Spec: marketing-ai-copy.md §5.
 */
class AiCopyUnavailableException extends RuntimeException
{
}
