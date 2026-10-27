<?php

namespace App\Events\Desk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CoreX Desk - one server-originated event on the session's private channel (spec .ai/specs/corex-desk.md section 7.2).
 * ShouldBroadcastNow: it goes out in the request that caused it, so the red bar appears within seconds without a queue worker
 * in the path. Payloads are tiny (names, a selector) and never carry anything from the agent's screen.
 *
 *   desk.joined {support_name, joined_at}   desk.highlight {selector, note}   desk.highlight_cleared {}
 *   desk.pointer_state {on}                 desk.ended {by}
 */
class DeskBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $sessionId,
        public string $name,
        public array $payload = [],
    ) {
    }

    public static function channelName(int $sessionId): string
    {
        return 'desk.session.' . $sessionId;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->sessionId))];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
