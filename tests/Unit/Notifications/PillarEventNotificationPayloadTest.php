<?php

namespace Tests\Unit\Notifications;

use App\Notifications\PillarEventNotification;
use App\Support\Notifications\AgeFormatter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance guard: the FCM push payload (notification.title/body) and the
 * in-app feed payload (toArray title/body) must agree exactly, and neither
 * may contain a decimal-hour value or placeholder filler.
 */
class PillarEventNotificationPayloadTest extends TestCase
{
    private const NO_DECIMAL = '/\d+\.\d+/';

    private function make(string $title, string $body): PillarEventNotification
    {
        $n = new PillarEventNotification(
            eventKey: 'property.documents_missing',
            pillar: 'property',
            title: $title,
            body: $body,
            subjectType: 'property',
            subjectId: 1,
            subjectLabel: '12 Seaview Drive',
            actionUrl: '/properties/1',
            severity: 'warning',
        );
        $n->id = 'test-uuid';
        return $n;
    }

    #[Test]
    public function push_and_in_app_payloads_agree_for_a_multi_day_item(): void
    {
        $now  = Carbon::parse('2026-06-03 12:00:00');
        $age  = AgeFormatter::ago($now->copy()->subHours(2097)->subMinutes(59), $now);
        $body = "Listed {$age}, no documents on file.";

        $n = $this->make('12 Seaview Drive — documents missing', $body);

        $fcm     = $n->toFcmPayload();
        $inApp   = $n->toArray((object) []);

        // Tray (FCM) and in-app feed read identically.
        $this->assertSame($inApp['title'], $fcm['notification']['title']);
        $this->assertSame($inApp['body'], $fcm['notification']['body']);
        $this->assertSame('Listed 87 days ago, no documents on file.', $fcm['notification']['body']);

        // No decimal-hour value survives into either surface.
        $this->assertDoesNotMatchRegularExpression(self::NO_DECIMAL, $fcm['notification']['body']);
        $this->assertDoesNotMatchRegularExpression(self::NO_DECIMAL, $inApp['body']);
    }

    #[Test]
    public function missing_optional_field_yields_clean_copy_with_no_filler(): void
    {
        // created_at missing → age clause dropped at source → clean fallback body.
        $age  = AgeFormatter::ago(null);
        $body = $age ? "Listed {$age}, no documents on file." : 'No documents on file.';

        $n   = $this->make('12 Seaview Drive — documents missing', $body);
        $fcm = $n->toFcmPayload();

        $this->assertSame('No documents on file.', $fcm['notification']['body']);
        $this->assertStringNotContainsString('null', $fcm['notification']['body']);
        $this->assertStringNotContainsString('h ago', $fcm['notification']['body']);
    }
}
