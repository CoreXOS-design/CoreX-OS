<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The agency has used all its AI template generations for the calendar month.
 * The message is the plain, user-facing sentence and names the reset date.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.4.
 */
final class AiTemplateLimitReachedException extends RuntimeException
{
    public function __construct(public readonly int $limit, public readonly CarbonInterface $resetsAt)
    {
        parent::__construct(
            $limit <= 0
                ? 'AI template generation is switched off for your agency. Please contact CoreX support.'
                : "Your agency has used all {$limit} AI template generations for this month. "
                    . 'You can make more from ' . $resetsAt->format('j F Y') . '.'
        );
    }
}
