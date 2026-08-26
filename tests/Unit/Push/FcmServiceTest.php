<?php

declare(strict_types=1);

namespace Tests\Unit\Push;

use App\Services\Push\FcmService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Regression for two real gaps found 2026-08-13 while diagnosing "push not
 * showing a pop-up" on the mobile app:
 *
 * 1. No android.notification.channel_id was ever sent, so Android 8+ routed
 *    every push into FCM's auto-created fallback channel at default
 *    importance — silently landing in the shade with no heads-up banner,
 *    regardless of any high-importance channel the app itself defined.
 * 2. `sent` was computed as count(tokens) - count(dead-per-unknown/invalid),
 *    so any OTHER per-message failure (auth error, quota, sender mismatch)
 *    was silently counted as a successful send.
 *
 * No DB, no HTTP — pure adapter logic against a mocked Messaging contract.
 */
final class FcmServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_attaches_the_shared_high_importance_android_channel(): void
    {
        $captured = null;

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$captured) {
                $captured = $message;
                return true;
            })
            ->andReturn(MulticastSendReport::withItems([
                SendReport::success(MessageTarget::with('token', 'tok-1'), []),
            ]));

        (new FcmService($messaging))->send(['tok-1'], [
            'notification' => ['title' => 'New P24 lead', 'body' => 'Jane Buyer'],
            'data'         => ['type' => 'portal_lead'],
        ]);

        $this->assertNotNull($captured);
        $json = $captured->jsonSerialize();
        $this->assertSame('corex_alerts', $json['android']['notification']['channel_id']);
        $this->assertSame('PRIORITY_HIGH', $json['android']['notification']['notification_priority']);
        $this->assertSame('high', $json['android']['priority']);
    }

    public function test_sent_count_reflects_actual_fcm_successes_not_tokens_minus_dead(): void
    {
        // 3 tokens: one genuine success, one genuinely dead (NotFound — prunable),
        // one a real per-message failure that is neither unknown nor invalid (e.g.
        // a sender-mismatch / transient auth error). The old formula
        // (count(tokens) - count(dead)) would report sent=2 here (only the
        // NotFound one subtracted) — wrongly counting the failed-but-not-dead
        // token as delivered.
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with('token', 'tok-ok'), []),
            SendReport::failure(MessageTarget::with('token', 'tok-dead'), new \Kreait\Firebase\Exception\Messaging\NotFound('gone')),
            SendReport::failure(MessageTarget::with('token', 'tok-failed'), new \RuntimeException('SenderIdMismatch')),
        ]));

        $result = (new FcmService($messaging))->send(['tok-ok', 'tok-dead', 'tok-failed'], [
            'notification' => ['title' => 't', 'body' => 'b'],
        ]);

        $this->assertSame(1, $result->sent, 'only the genuine success counts as sent');
        $this->assertSame(['tok-dead'], $result->deadTokens, 'only the NotFound token is pruned, not the generic failure');
    }
}
