<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Notifications\ClauseFlaggedNotification;
use Database\Seeders\NotificationEventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-299 — a recipient flagging a clause freezes the ceremony (AT-291 ⑤) but
 * the sending AGENT was never told, and the frozen document (STATUS_AMENDMENT_
 * REVIEW) was in no myDocuments bucket, so it was invisible. This pins the
 * agent notification (via the AT-235 gateway) fired from flagClause.
 */
final class ClauseFlagNotifiesAgentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_flagging_a_clause_notifies_the_sending_agent(): void
    {
        $this->seed(NotificationEventTypeSeeder::class); // esign.clause_flagged catalogue row, default ON
        Notification::fake();

        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->postJson(route('signatures.external.flagClause', ['token' => $seller1->token]), [
            'clause_ref'           => '3.7',
            'clause_original_text' => 'The notice period is 30 days.',
            'suggested_change'     => 'Please make the notice period 60 days.',
        ]);

        $response->assertStatus(201);

        // The sending agent (template creator) is notified through the gateway.
        Notification::assertSentTo(
            $session['creator'],
            ClauseFlaggedNotification::class,
        );
    }
}
