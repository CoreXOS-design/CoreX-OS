<?php

namespace Tests\Support;

use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushSendResult;

/**
 * Test double for the push transport. Records every delivery attempt so tests
 * can assert exactly how many pushes reached a device, and can be configured to
 * simulate transient transport failures (for the bounded-retry test) and
 * permanently-dead tokens (for the prune test).
 */
class SpyPushTransport implements PushTransport
{
    /** @var array<int, array{tokens: string[], payload: array}> successful deliveries */
    public array $calls = [];

    /** Total times send() was invoked, including attempts that threw. */
    public int $attempts = 0;

    /** Throw a transient failure this many times before succeeding. */
    public int $failTimes = 0;

    /** Tokens to report back as permanently dead. */
    public array $deadTokens = [];

    public bool $operational = true;

    private int $failsSoFar = 0;

    public function send(array $tokens, array $payload): PushSendResult
    {
        $this->attempts++;

        if ($this->failsSoFar < $this->failTimes) {
            $this->failsSoFar++;
            throw new \RuntimeException('Simulated transient FCM failure');
        }

        $tokens = array_values($tokens);
        $this->calls[] = ['tokens' => $tokens, 'payload' => $payload];

        $dead = array_values(array_intersect($tokens, $this->deadTokens));

        return new PushSendResult(
            sent: count($tokens) - count($dead),
            deadTokens: $dead,
        );
    }

    public function isOperational(): bool
    {
        return $this->operational;
    }

    /** Total distinct-token deliveries across all successful calls. */
    public function totalTokensSent(): int
    {
        return array_sum(array_map(static fn ($c) => count($c['tokens']), $this->calls));
    }

    /** How many times a specific token was actually delivered to. */
    public function timesSentTo(string $token): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            $n += count(array_keys($call['tokens'], $token, true));
        }
        return $n;
    }
}
