<?php

namespace App\Exceptions\Rentals;

use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.4 step 1 / §15.16 (Build L2) — "Create lease & prepare for signing" with agreement
 * details the agency's own lease marks as required still blank. Nothing is created; the agent is told which
 * and stays on the screen. Carries the list as `missing` ([{key, label}]) for the API's
 * `422 {missing: [...]}`, and as field errors (`agreement.<key>`) for the web screen.
 */
class LeaseCaptureIncompleteException extends ValidationException
{
    /** @var array<int, array{key: string, label: string}> */
    public array $missing = [];

    /**
     * @param array<int, array{key: string, label: string}> $missing
     */
    public static function forMissing(array $missing): self
    {
        $messages = [];
        foreach ($missing as $item) {
            $messages['agreement.' . $item['key']] = [$item['label'] . ' is needed for signing.'];
        }

        $exception = self::withMessages($messages);
        $exception->missing = $missing;

        return $exception;
    }
}
