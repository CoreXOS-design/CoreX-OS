<?php

declare(strict_types=1);

namespace App\Exceptions\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use RuntimeException;

/**
 * CX-113 Phase A (Johan, 2026-08-21) — thrown by CommunicationDealLinkingService::link()
 * when a second filer targets an email that a first filer already filed to a DIFFERENT
 * deal, inside the same locked transaction. "First wins, second is told 'already filed
 * to #1775' with the option to move it. Never silently overwritten." Carries the
 * surviving link so the caller can name the deal it already landed on and offer a
 * move (re-call link() with $move = true).
 */
final class AlreadyFiledException extends RuntimeException
{
    public function __construct(
        public readonly Communication $communication,
        public readonly CommunicationLink $existingLink,
    ) {
        parent::__construct('This email was already filed to another deal.');
    }
}
