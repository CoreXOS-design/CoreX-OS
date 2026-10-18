<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Thrown by AiSpendGuard::assertAllowed() when the agency (or the platform
 * system bucket) has been cut out for AI spend. The message is the plain,
 * user-facing sentence — callers show it as-is.
 *
 * Extends RuntimeException so every existing "AI failed" catch path still
 * handles it; callers that can show a message catch it specifically first.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.2.
 */
final class AiPausedException extends RuntimeException
{
    public const MESSAGE = 'AI is paused for your agency — please contact CoreX support.';

    public function __construct(public readonly ?int $agencyId = null)
    {
        parent::__construct(self::MESSAGE);
    }
}
