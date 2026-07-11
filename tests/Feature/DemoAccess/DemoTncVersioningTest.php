<?php

namespace Tests\Feature\DemoAccess;

use App\Models\DemoTncAcceptance;
use App\Models\DemoTncVersion;
use App\Models\User;
use App\Services\Demo\DemoAccessService;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * T&C versions are IMMUTABLE, and acceptance is evidence.
 *
 * Spec: .ai/specs/demo-access-control.md §4.1, §4.3
 * Input space (§11): R12, R13
 *
 * The property under test is the one that makes clickwrap worth anything: an
 * acceptance must point at the exact text that was on screen, forever — not at
 * whatever that text was later edited into.
 */
class DemoTncVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private DemoAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner   = User::factory()->create(['role' => 'super_admin']);
        $this->service = app(DemoAccessService::class);

        $this->seed(DemoTncVersionSeeder::class);
    }

    private function grant(string $company = 'Seaside Realty (Pty) Ltd', string $email = 'thabo@seasiderealty.co.za')
    {
        [$grant, $code] = $this->service->issue([
            'company_name'  => $company,
            'contact_email' => $email,
        ], $this->owner->id);

        return [$grant, $code];
    }

    /** Publishing takes the next version number. It never updates in place. */
    public function test_publishing_mints_a_new_version_and_never_edits_the_old_one(): void
    {
        $v1 = DemoTncVersion::current();
        $this->assertSame(1, $v1->version);
        $originalBody = $v1->body;

        $v2 = DemoTncVersion::publish('Version two of the demo terms. Materially different text.', $this->owner->id);

        $this->assertSame(2, $v2->version);
        $this->assertSame(2, DemoTncVersion::current()->version);

        // v1 is untouched — same row, same text.
        $this->assertSame($originalBody, $v1->fresh()->body);
        $this->assertSame(2, DemoTncVersion::count());
    }

    /**
     * R13 — THE POINT OF THE WHOLE DESIGN.
     *
     * Publishing v2 re-prompts a user who accepted v1, AND their v1 acceptance
     * still renders the v1 body. If v1's text had been edited in place, that
     * acceptance would now be evidence of words nobody ever agreed to.
     */
    public function test_publishing_v2_reprompts_everyone_and_v1_acceptances_still_show_v1_text(): void
    {
        [$grant] = $this->grant();

        $v1 = DemoTncVersion::current();
        $this->service->acceptTnc($grant, '196.25.1.1', 'Chrome');

        $this->assertTrue($grant->fresh()->hasAcceptedCurrentTnc());

        // A new version ships while they are mid-session.
        $v2 = DemoTncVersion::publish('We have added a clause about data retention. Please re-accept.', $this->owner->id);

        // They are re-prompted...
        $this->assertFalse($grant->fresh()->hasAcceptedCurrentTnc());

        // ...and their v1 acceptance still points at, and renders, the v1 body.
        $acceptance = DemoTncAcceptance::where('demo_access_grant_id', $grant->id)->first();
        $this->assertSame($v1->id, $acceptance->demo_tnc_version_id);
        $this->assertSame($v1->body, $acceptance->version->body);
        $this->assertNotSame($v2->body, $acceptance->version->body);

        // Accepting v2 adds a SECOND acceptance — the v1 one is not overwritten.
        $this->service->acceptTnc($grant->fresh(), '196.25.1.1', 'Chrome');
        $this->assertSame(2, DemoTncAcceptance::where('demo_access_grant_id', $grant->id)->count());
        $this->assertTrue($grant->fresh()->hasAcceptedCurrentTnc());
    }

    /** R12 — a double-submit produces ONE acceptance row, not two. */
    public function test_accepting_twice_is_idempotent(): void
    {
        [$grant] = $this->grant();

        $first  = $this->service->acceptTnc($grant, '196.25.1.1', 'Chrome');
        $second = $this->service->acceptTnc($grant->fresh(), '196.25.1.1', 'Chrome');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DemoTncAcceptance::where('demo_access_grant_id', $grant->id)->count());
    }

    /**
     * With NO published T&C, the clickwrap FAILS CLOSED.
     *
     * "There is no text to show" is not a reason to waive a legal control. This is
     * why DemoTncVersionSeeder is registered in deploy:sync-reference-data — a
     * deploy that forgets v1 blocks every prospect, loudly, rather than quietly
     * letting them in unbound.
     */
    public function test_with_no_published_terms_nobody_is_considered_accepted(): void
    {
        DemoTncVersion::query()->delete();

        [$grant] = $this->grant();

        $this->assertNull(DemoTncVersion::current());
        $this->assertFalse($grant->hasAcceptedCurrentTnc());
        $this->assertNull($this->service->acceptTnc($grant, null, null));
    }

    /** The acceptance captures who, when, and from where — it is evidence. */
    public function test_acceptance_records_the_forensics(): void
    {
        [$grant] = $this->grant();

        $acceptance = $this->service->acceptTnc($grant, '196.25.1.1', 'Mozilla/5.0 Chrome/120');

        $this->assertSame('196.25.1.1', $acceptance->ip_address);
        $this->assertStringContainsString('Chrome', $acceptance->user_agent);
        $this->assertNotNull($acceptance->accepted_at);
        $this->assertSame($grant->id, $acceptance->grant->id);
    }
}
