<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use RuntimeException;

/**
 * A template generation the author can fix themselves (e.g. nothing tagged yet).
 * Its message is a plain sentence written for the user and is shown as-is;
 * every other generation failure is logged and shown only as a support reference.
 */
final class TemplateGenerationRefused extends RuntimeException
{
}
