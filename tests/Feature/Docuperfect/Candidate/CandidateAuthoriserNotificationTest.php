<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Notifications\SignatureActivityNotification;
use Tests\TestCase;

/**
 * Candidate-flow rework — authoriser notification is IN-APP ONLY (database channel), never email.
 */
final class CandidateAuthoriserNotificationTest extends TestCase
{
    public function test_candidate_needs_authorisation_is_in_app_database_channel_not_email(): void
    {
        $n = SignatureActivityNotification::candidateNeedsAuthorisation(
            'Angelique Venter',
            'Exclusive Authority to Sell',
            4242,
            'https://qa/review/4242',
            'initial_review',
        );

        // Channel is in-app database ONLY — no mail channel.
        $this->assertSame(['database'], $n->via(new \stdClass()));
        $this->assertNotContains('mail', $n->via(new \stdClass()));

        $payload = $n->toArray(new \stdClass());
        $this->assertSame('candidate_needs_authorisation', $payload['type']);
        $this->assertSame(4242, $payload['document_id']);
        $this->assertSame('https://qa/review/4242', $payload['url']);
        $this->assertStringContainsString('Angelique Venter', $payload['message']);
        $this->assertStringContainsString('Exclusive Authority to Sell', $payload['message']);
        $this->assertSame('initial_review', $payload['metadata']['review_type']);
    }
}
