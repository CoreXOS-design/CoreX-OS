<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Notifications\PillarEventNotification;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.4 — releasing a document tells the agency's authorisers (and only them).
 */
final class TemplateReleaseNotifyTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    public function test_the_agencys_authorisers_are_told_and_nobody_else(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $other = $this->agency('Another Agency');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);                     // admin fallback: nobody named yet
        $agent = $this->agencyUserWithRole($client, 'agent');
        $foreignAdmin = $this->agencyAdmin($other);
        Notification::fake();

        $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        Notification::assertSentTo($admin, PillarEventNotification::class, fn ($n) => $n->eventKey === 'template_release_awaiting_approval' && str_contains((string) $n->actionUrl, '/template-approvals/') && $n->channels === ['database', 'mail']);
        Notification::assertNotSentTo($agent, PillarEventNotification::class);
        Notification::assertNotSentTo($foreignAdmin, PillarEventNotification::class);
        Notification::assertNotSentTo($owner, PillarEventNotification::class);
    }

    public function test_once_people_are_named_they_are_the_ones_told(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent');
        app(AgencyDocumentAuthorisers::class)->save($client->id, [$agent->id], null, $admin);
        Notification::fake();

        $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        Notification::assertSentTo($agent, PillarEventNotification::class);
        Notification::assertNotSentTo($admin, PillarEventNotification::class);
    }

    public function test_a_release_still_succeeds_when_nobody_can_be_told(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Empty Agency');   // no users at all
        $owner = $this->owner();
        Notification::fake();

        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $this->assertSame('awaiting_approval', $release->state);
        Notification::assertNothingSent();
    }
}
