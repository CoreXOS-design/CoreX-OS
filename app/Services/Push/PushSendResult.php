<?php

namespace App\Services\Push;

/**
 * Outcome of a single PushTransport::send() attempt.
 */
final class PushSendResult
{
    /**
     * @param  int       $sent        Number of tokens the provider accepted for delivery.
     * @param  string[]  $deadTokens  Tokens the provider rejected as permanently
     *                                unregistered/invalid — prune these, never retry.
     */
    public function __construct(
        public readonly int $sent = 0,
        public readonly array $deadTokens = [],
    ) {}

    public static function none(): self
    {
        return new self(0, []);
    }
}
