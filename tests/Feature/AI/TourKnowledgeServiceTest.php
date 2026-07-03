<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\User;
use App\Services\AI\TourKnowledgeService;
use Mockery;
use Tests\TestCase;

/**
 * Guards Ellie's "how do I do X" answers, sourced from the guided-tour catalogue.
 */
final class TourKnowledgeServiceTest extends TestCase
{
    private function service(): TourKnowledgeService
    {
        return app(TourKnowledgeService::class);
    }

    private function user(bool $grants, bool $isOwner = false): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasPermission')->andReturn($grants);
        $user->shouldReceive('isOwnerRole')->andReturn($isOwner);

        return $user;
    }

    public function test_buyer_pipeline_question_returns_the_how_to_steps(): void
    {
        $matches = $this->service()->search(
            'how do i get a buyer on the buyer pipeline',
            $this->user(true),
            2,
        );

        $this->assertNotEmpty($matches);
        $this->assertSame('buyer-pipeline', $matches[0]['key']);
        $this->assertNotEmpty($matches[0]['steps']);
    }

    public function test_build_context_includes_steps_and_a_link(): void
    {
        $result = $this->service()->buildContext(
            'how do i work my buyer pipeline',
            $this->user(true),
            2,
        );

        $this->assertStringContainsString('Steps:', $result['context']);
        $this->assertStringContainsString('Direct link: /corex/command-center/buyers/pipeline', $result['context']);
        // The actual answer the plain navigation link could not give:
        $this->assertStringContainsStringIgnoringCase('automatically', $result['context']);
    }

    public function test_permission_gated_tours_are_hidden(): void
    {
        // The outreach-summary tour declares permission 'outreach.summary.view'.
        $denied = $this->service()->search('reading the outreach scoreboard board', $this->user(false), 3);
        $this->assertEmpty(
            array_filter($denied, fn ($t) => $t['key'] === 'outreach-summary'),
            'A user without the permission must not see the gated tour.',
        );

        $granted = $this->service()->search('reading the outreach scoreboard board', $this->user(true), 3);
        $this->assertNotEmpty(
            array_filter($granted, fn ($t) => $t['key'] === 'outreach-summary'),
            'A permitted user should see the tour.',
        );
    }

    public function test_unrelated_question_returns_nothing(): void
    {
        $matches = $this->service()->search('what is the prime lending rate', $this->user(true), 2);
        $this->assertEmpty($matches, 'Tour knowledge should not fire for non-workflow questions.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
