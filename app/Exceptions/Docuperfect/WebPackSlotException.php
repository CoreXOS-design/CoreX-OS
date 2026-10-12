<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use RuntimeException;

/**
 * A web pack could not be resolved into the set of documents to send.
 *
 * Every message on this exception is shown to the AGENT, verbatim, at send time — so each one
 * says what is wrong AND what to do about it (BUILD_STANDARD §4). None of them is a stack trace.
 */
final class WebPackSlotException extends RuntimeException
{
}
