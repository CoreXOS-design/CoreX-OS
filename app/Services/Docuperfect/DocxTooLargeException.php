<?php

namespace App\Services\Docuperfect;

/**
 * A Word file whose zip container declares more than the importer will inflate (zip-bomb guard).
 * A RuntimeException, so callers that already show a parse RuntimeException's message to the author keep working.
 */
final class DocxTooLargeException extends \RuntimeException
{
}
