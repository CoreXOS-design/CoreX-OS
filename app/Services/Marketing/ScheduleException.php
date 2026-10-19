<?php

declare(strict_types=1);

namespace App\Services\Marketing;

/**
 * AT-456 Part D — a scheduling rule was broken. The message is a plain sentence meant for the agent
 * (too soon, too far, no connected account, not yours to post for ...), shown on screen as-is.
 */
final class ScheduleException extends \RuntimeException
{
}
