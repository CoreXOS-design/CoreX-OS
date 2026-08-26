<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use RuntimeException;

/**
 * A web pack could not be resolved into the set of documents to send.
 *
 * Every message on this exception is shown to the AGENT, verbatim, at send time — so each one
 * says what is wrong AND what to do about it (BUILD_STANDARD §4). None of them is a stack trace.
 *
 * `$esignBlocked` marks the one refusal that is legal rather than procedural: an alienation
 * document (sale agreement / OTP) may not be e-signed under ECTA §13(1) — a sale e-signed is not
 * "flagged", it is VOID. The wizard surfaces that refusal differently from a mis-picked slot.
 */
final class WebPackSlotException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $esignBlocked = false)
    {
        parent::__construct($message);
    }

    public static function esignBlocked(string $templateName): self
    {
        return new self(
            "“{$templateName}” is a sale agreement or offer to purchase. It must be signed with wet ink "
            . 'per the Alienation of Land Act — e-signing it would make the sale void. Remove it from the '
            . 'pack, or send the rest of the pack without it.',
            esignBlocked: true,
        );
    }
}
