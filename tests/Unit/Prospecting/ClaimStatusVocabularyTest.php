<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Models\ProspectingClaim;
use App\Services\Prospecting\ClaimFeedbackTemplates;
use PHPUnit\Framework\TestCase;

/**
 * Part 1 — claim status vocabulary reconciliation guard.
 *
 * Locks the single-source-of-truth contract: every status a quick-pick feedback
 * template can set MUST be a canonical ProspectingClaim feedback status (i.e. one
 * the feedback() validator accepts). Before the 2026-06-26 reconciliation the
 * templates emitted interested / pitched / scheduled, none of which exist in the
 * validator — they would 422. This test prevents that drift from returning.
 *
 * Pure unit test — no DB, no HTTP. Fast and infra-independent.
 */
final class ClaimStatusVocabularyTest extends TestCase
{
    public function test_every_template_status_is_a_canonical_feedback_status(): void
    {
        $canonical = ProspectingClaim::FEEDBACK_STATUSES;

        foreach (ClaimFeedbackTemplates::getTemplates() as $tpl) {
            $this->assertContains(
                $tpl['status'],
                $canonical,
                "Template '{$tpl['key']}' sets status '{$tpl['status']}' which is not a canonical "
                . 'ProspectingClaim::FEEDBACK_STATUSES value — the feedback() validator would reject it.'
            );
        }
    }

    public function test_killed_vocabulary_no_longer_appears_in_any_template(): void
    {
        $killed = ['interested', 'pitched', 'scheduled'];

        foreach (ClaimFeedbackTemplates::getTemplates() as $tpl) {
            $this->assertNotContains(
                $tpl['status'],
                $killed,
                "Template '{$tpl['key']}' still uses a retired status token."
            );
        }
    }

    public function test_feedback_statuses_are_a_subset_of_all_statuses_and_exclude_the_initial_claimed(): void
    {
        // The initial 'claimed' state is set only at claim time, never via feedback.
        $this->assertNotContains(ProspectingClaim::STATUS_CLAIMED, ProspectingClaim::FEEDBACK_STATUSES);

        foreach (ProspectingClaim::FEEDBACK_STATUSES as $status) {
            $this->assertContains($status, ProspectingClaim::STATUSES);
        }

        // Closing statuses (auto-release) must themselves be valid feedback statuses.
        foreach (ProspectingClaim::CLOSING_STATUSES as $status) {
            $this->assertContains($status, ProspectingClaim::FEEDBACK_STATUSES);
        }
    }

    public function test_human_status_is_plain_english_and_never_raw_enum(): void
    {
        $this->assertSame('Meeting set', ProspectingClaim::humanStatus(ProspectingClaim::STATUS_MEETING_SET));
        $this->assertSame('Not interested', ProspectingClaim::humanStatus(ProspectingClaim::STATUS_NOT_INTERESTED));
        // Unknown / null never leaks a raw token.
        $this->assertSame('Worked', ProspectingClaim::humanStatus(null));
        $this->assertSame('Some thing', ProspectingClaim::humanStatus('some_thing'));
    }
}
