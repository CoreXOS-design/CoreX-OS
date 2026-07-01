<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\User;
use App\Services\AI\NavigationAtlasService;
use Mockery;
use Tests\TestCase;

/**
 * Guards Ellie's "where do I go to…" navigation answers.
 *
 * The service is exercised against the real route table + real registry
 * (config/corex-navigation-atlas.php). Permission checks are mocked on the
 * User so the tests are deterministic regardless of role_permissions seeding.
 */
final class NavigationAtlasServiceTest extends TestCase
{
    private function service(): NavigationAtlasService
    {
        return app(NavigationAtlasService::class);
    }

    /** A user double whose permission answers we control. */
    private function user(bool $grants, bool $isOwner = false): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasPermission')->andReturn($grants);
        $user->shouldReceive('isOwnerRole')->andReturn($isOwner);

        return $user;
    }

    public function test_detects_navigation_intent(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->isNavigationQuery('Where do I go to do a new presentation?'));
        $this->assertTrue($svc->isNavigationQuery('how do i create a deal'));
        $this->assertTrue($svc->isNavigationQuery('take me to my contacts'));

        $this->assertFalse($svc->isNavigationQuery('What is the prime lending rate?'));
        $this->assertFalse($svc->isNavigationQuery('Summarise this OTP clause for me'));
    }

    public function test_presentation_creation_points_at_the_property_flow(): void
    {
        // Presentations are created FROM a property (Generate Presentation) — there
        // is no standalone builder — so the create intent must land on Properties.
        $matches = $this->service()->search(
            'Where do I go to do a new presentation for a property?',
            $this->user(true),
            3,
        );

        $this->assertNotEmpty($matches, 'Expected a navigation match for presentation creation.');

        $top = $matches[0];
        $this->assertSame('corex.properties.index', $top['route']);
        $this->assertSame('/corex/properties', $top['url']);
    }

    public function test_build_context_embeds_the_direct_link(): void
    {
        $result = $this->service()->buildContext(
            'where do i create a presentation',
            $this->user(true),
            3,
        );

        $this->assertStringContainsString('/corex/properties', $result['context']);
        $this->assertStringContainsString('Direct link:', $result['context']);
        $this->assertNotEmpty($result['sources']);
        $this->assertSame('/corex/properties', $result['sources'][0]['url']);
        // The old standalone builder must never be surfaced again.
        $this->assertStringNotContainsString('/presentations/create', $result['context']);
    }

    public function test_excludes_destinations_the_user_cannot_access(): void
    {
        // presentations.* are gated by permission:access_presentations — a user
        // denied that permission must get no presentation links.
        $matches = $this->service()->search(
            'where do i create a presentation',
            $this->user(false),
            5,
        );

        $presentationMatches = array_filter($matches, fn ($m) => str_contains($m['route'], 'presentation'));
        $this->assertEmpty($presentationMatches, 'A user without access must not be shown presentation links.');
    }

    public function test_null_user_is_denied_permission_gated_destinations(): void
    {
        $matches = $this->service()->search('where do i create a presentation', null, 5);

        $presentationMatches = array_filter($matches, fn ($m) => str_contains($m['route'], 'presentation'));
        $this->assertEmpty($presentationMatches, 'A null user must not be shown permission-gated links.');
    }

    public function test_owner_only_destinations_are_hidden_from_non_owners(): void
    {
        // agencies.index is gated by the `owner_only` middleware.
        $nonOwner = $this->service()->search('manage agencies', $this->user(true, false), 5);
        $this->assertEmpty(
            array_filter($nonOwner, fn ($m) => $m['route'] === 'agencies.index'),
            'A non-owner must not be shown the owner-only Agencies page.',
        );

        $owner = $this->service()->search('manage agencies', $this->user(true, true), 5);
        $this->assertNotEmpty(
            array_filter($owner, fn ($m) => $m['route'] === 'agencies.index'),
            'An owner should see the Agencies page.',
        );
    }

    public function test_returns_only_relative_resolvable_links(): void
    {
        $matches = $this->service()->search('where do i find my contacts', $this->user(true), 5);

        $this->assertNotEmpty($matches);
        foreach ($matches as $m) {
            $this->assertStringStartsWith('/', $m['url'], "URL for {$m['route']} must be a relative path.");
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
