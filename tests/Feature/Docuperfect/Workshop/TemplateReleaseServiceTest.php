<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\RentalLeaseTemplate;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13 — verify, release (lands NOT live), approve, decline, withdraw, release again (new version),
 * supersede, idempotency, soft-delete-recreate, readiness at approval, the lease link at approval, and documents in flight untouched.
 */
final class TemplateReleaseServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private function svc(): TemplateReleaseService
    {
        return app(TemplateReleaseService::class);
    }

    private function refuses(callable $fn, string $contains = ''): TemplateReleaseException
    {
        try {
            $fn();
        } catch (TemplateReleaseException $e) {
            if ($contains !== '') {
                $this->assertStringContainsString($contains, $e->getMessage());
            }

            return $e;
        }
        $this->fail('expected a TemplateReleaseException');
    }

    // ── verify ───────────────────────────────────────────────────────────────

    public function test_verify_needs_the_tick_a_clean_report_and_an_active_template(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);

        $this->refuses(fn () => $this->svc()->verify($t, $owner, false), 'Tick');
        $this->assertNull($t->fresh()->workshop_verified_at);

        $broken = $this->megaShaped($w, $owner, ['signing_parties' => []]);
        $e = $this->refuses(fn () => $this->svc()->verify($broken, $owner, true), 'must be fixed');
        $this->assertNotEmpty($e->details);

        $archived = $this->megaShaped($w, $owner, ['archived_at' => now()]);
        $this->refuses(fn () => $this->svc()->verify($archived, $owner, true), 'archived');

        $v = $this->svc()->verify($t, $owner, true);
        $this->assertNotNull($v->workshop_verified_at);
        $this->assertTrue($v->isVerifiedAndCurrent());
        $this->assertSame(1, TemplateReleaseEvent::where('event', 'verified')->count());
    }

    public function test_only_the_owner_can_verify_or_release(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        $admin = $this->agencyAdmin($w);

        $this->assertSame(403, $this->refuses(fn () => $this->svc()->verify($t, $admin, true))->status);
        $this->svc()->verify($t, $owner, true);
        $client = $this->agency('Cape Town Lettings');
        $this->assertSame(403, $this->refuses(fn () => $this->svc()->release($t->fresh(), $client, $admin))->status);
    }

    public function test_a_change_after_verification_makes_it_stale_and_blocks_release(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        $this->svc()->verify($t, $owner, true);
        $this->assertTrue($t->fresh()->isVerifiedAndCurrent());

        $cds = $t->fresh()->cds_json;
        $cds['title'] = 'Changed after verification';
        $t->update(['cds_json' => $cds]);

        $this->assertFalse($t->fresh()->isVerifiedAndCurrent());
        $this->refuses(fn () => $this->svc()->release($t->fresh(), $client, $owner), 'changed after it was verified');
        $this->assertSame(0, TemplateRelease::withoutGlobalScopes()->count());
    }

    public function test_release_needs_verification_a_client_agency_and_no_double_submit(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);

        $this->refuses(fn () => $this->svc()->release($t, $client, $owner), 'Verify the template first');
        $this->svc()->verify($t, $owner, true);
        $this->refuses(fn () => $this->svc()->release($t->fresh(), $w, $owner), 'client agency');

        [$r1] = $this->releasedCopy($t->fresh(), $client, $owner);
        $this->assertSame(1, $r1->version);
        $this->refuses(fn () => $this->svc()->release($t->fresh(), $client, $owner), 'already waiting for approval');
        $this->assertSame(1, TemplateRelease::withoutGlobalScopes()->count());
    }

    // ── release lands NOT live ────────────────────────────────────────────────

    public function test_a_release_creates_an_agency_copy_that_is_held_back_and_logged(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        [$release, $copy] = $this->releasedCopy($t, $client, $owner);

        $this->assertSame((int) $client->id, (int) $copy->agency_id);
        $this->assertNotSame($t->id, $copy->id);
        $this->assertSame('awaiting_approval', $copy->release_status);
        $this->assertTrue($copy->isHeldBack());
        $this->assertSame('awaiting_approval', $release->state);
        $this->assertSame((int) $t->id, (int) $release->source_template_id);
        $this->assertNotEmpty($release->readiness_snapshot['checks']);
        $this->assertSame(['released'], TemplateReleaseEvent::where('release_id', $release->id)->pluck('event')->all());

        $admin = $this->agencyAdmin($client);
        $this->assertFalse(Template::active()->visibleTo($admin)->whereKey($copy->id)->exists(), 'not in any picker or list');
        $this->assertFalse(Template::visibleTo($admin)->whereKey($copy->id)->exists());
        $this->assertSame('live', $this->svc()->approve($release, $admin, true)['release']->state);
        $this->assertTrue(Template::active()->visibleTo($admin)->whereKey($copy->id)->exists(), 'live once approved');
    }

    // ── approve / decline ─────────────────────────────────────────────────────

    public function test_only_a_named_authoriser_can_approve_and_the_fallback_is_the_admin(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $agent = $this->agencyUserWithRole($client, 'agent');
        $admin = $this->agencyAdmin($client);
        $otherAdmin = $this->agencyAdmin($this->agency('Another Agency'));

        // Nobody named: the admin fallback applies; an agent, the owner and another agency's admin cannot.
        $this->assertSame(403, $this->refuses(fn () => $this->svc()->approve($release, $agent, true))->status);
        $this->assertSame(403, $this->refuses(fn () => $this->svc()->approve($release, $owner, true))->status);
        $this->assertSame(403, $this->refuses(fn () => $this->svc()->approve($release, $otherAdmin, true))->status);

        // Name the agent: now the agent can and the admin (not named) cannot.
        app(\App\Services\Docuperfect\Release\AgencyDocumentAuthorisers::class)->save($client->id, [$agent->id], null, $admin);
        $this->assertSame(403, $this->refuses(fn () => $this->svc()->approve($release, $admin, true))->status);
        $result = $this->svc()->approve($release, $agent, true, 'Looks right');
        $this->assertSame('live', $result['release']->state);
        $this->assertSame((int) $agent->id, (int) $result['release']->decided_by);
        $this->assertSame('Looks right', $result['release']->decision_note);
        $this->assertSame('live', Template::withoutGlobalScopes()->find($release->template_id)->release_status);
    }

    public function test_a_role_ticked_in_the_setting_may_approve_but_only_with_the_permission_key(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $admin = $this->agencyAdmin($client);
        $manager = $this->agencyUserWithRole($client, 'branch_manager');                       // has the key
        $noKey = $this->agencyUserWithRole($client, 'bookkeeper', []);                         // role ticked but no key
        $auth = app(\App\Services\Docuperfect\Release\AgencyDocumentAuthorisers::class);
        $auth->save($client->id, null, ['branch_manager', 'bookkeeper'], $admin);

        $this->assertSame(403, $this->refuses(fn () => $this->svc()->approve($release, $noKey, true))->status, 'a ticked role without the key cannot');
        $this->assertSame('live', $this->svc()->approve($release, $manager, true)['release']->state);
    }

    public function test_approve_needs_the_tick_and_is_idempotent(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $admin = $this->agencyAdmin($client);

        $this->refuses(fn () => $this->svc()->approve($release, $admin, false), 'Tick');
        $this->assertSame('awaiting_approval', $release->fresh()->state);

        $this->svc()->approve($release, $admin, true);
        $again = $this->svc()->approve($release, $admin, true);   // the second click
        $this->assertSame('live', $again['release']->state);
        $this->assertSame(1, TemplateReleaseEvent::where('release_id', $release->id)->where('event', 'approved')->count());
    }

    public function test_approval_is_refused_when_the_copy_now_has_a_failing_check(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $editor = $copy->editor_state;
        unset($editor['mappings']['tag-b']);   // a blank loses its link after release
        \DB::table('docuperfect_templates')->where('id', $copy->id)->update(['editor_state' => json_encode($editor)]);

        $this->refuses(fn () => $this->svc()->approve($release, $this->agencyAdmin($client), true), 'problem');
        $this->assertSame('awaiting_approval', $release->fresh()->state);
    }

    public function test_decline_needs_a_reason_keeps_it_hidden_and_a_re_release_is_the_next_version(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        [$release, $copy] = $this->releasedCopy($t, $client, $owner);
        $admin = $this->agencyAdmin($client);

        $this->refuses(fn () => $this->svc()->decline($release, $admin, '   '), 'Say why');
        $this->refuses(fn () => $this->svc()->decline($release, $admin, str_repeat('x', 1001)), 'under 1000');
        $declined = $this->svc()->decline($release, $admin, '  Clause 4 is wrong  ');
        $this->assertSame('declined', $declined->state);
        $this->assertSame('Clause 4 is wrong', $declined->decision_note);
        $this->assertSame('declined', Template::withoutGlobalScopes()->find($copy->id)->release_status);
        $this->assertTrue(Template::withoutGlobalScopes()->find($copy->id)->isHeldBack());

        // Fix in the workshop, verify again, release again: version 2, the declined row stays.
        $cds = $t->fresh()->cds_json;
        $cds['title'] = 'Fixed clause 4';
        $t->update(['cds_json' => $cds]);
        $this->svc()->verify($t->fresh(), $owner, true);
        $r2 = $this->svc()->release($t->fresh(), $client, $owner);
        $this->assertSame(2, $r2->version);
        $this->trackBlade(Template::withoutGlobalScopes()->find($r2->template_id));
        $this->assertSame('declined', $release->fresh()->state);
        $this->assertSame('awaiting_approval', $r2->state);
    }

    public function test_withdraw_archives_and_release_again_makes_the_next_version(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        [$release, $copy] = $this->releasedCopy($t, $client, $owner);

        $this->refuses(fn () => $this->svc()->withdraw($release, $this->agencyAdmin($client)), 'CoreX administrator');
        $w1 = $this->svc()->withdraw($release, $owner, 'Sent to the wrong agency');
        $this->assertSame('withdrawn', $w1->state);
        $this->assertNotNull(Template::withoutGlobalScopes()->find($copy->id)->archived_at);
        $this->refuses(fn () => $this->svc()->withdraw($w1, $owner), 'still waiting');

        $r2 = $this->svc()->release($t->fresh(), $client, $owner);   // still verified and unchanged
        $this->trackBlade(Template::withoutGlobalScopes()->find($r2->template_id));
        $this->assertSame(2, $r2->version);
    }

    public function test_a_soft_deleted_release_still_counts_for_the_next_version(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        [$release] = $this->releasedCopy($t, $client, $owner);
        $this->svc()->withdraw($release, $owner);
        TemplateRelease::withoutGlobalScopes()->whereKey($release->id)->first()->delete();   // soft delete

        $r2 = $this->svc()->release($t->fresh(), $client, $owner);
        $this->trackBlade(Template::withoutGlobalScopes()->find($r2->template_id));
        $this->assertSame(2, $r2->version, 'create -> soft-delete -> recreate must not collide with the unique version key');
    }

    // ── supersede and documents in flight ─────────────────────────────────────

    public function test_approving_a_newer_version_replaces_the_older_without_touching_documents_in_flight(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        $admin = $this->agencyAdmin($client);

        [$r1, $copy1] = $this->releasedCopy($t, $client, $owner);
        $this->svc()->approve($r1, $admin, true);
        $doc = $this->sentDocument($copy1->fresh(), $owner, $client);
        $docBefore = \DB::table('documents')->where('id', $doc->id)->first();
        $tplBefore = Template::withoutGlobalScopes()->find($copy1->id)->only(['cds_json', 'editor_state', 'name', 'signing_parties']);

        $cds = $t->fresh()->cds_json;
        $cds['title'] = 'Version two';
        $t->update(['cds_json' => $cds]);
        $this->svc()->verify($t->fresh(), $owner, true);
        $r2 = $this->svc()->release($t->fresh(), $client, $owner);
        $copy2 = Template::withoutGlobalScopes()->find($r2->template_id);
        $this->trackBlade($copy2);
        $this->assertSame($r1->id, $r2->previous_release_id);
        $this->assertSame('live', $r1->fresh()->state, 'v1 stays live until v2 is approved');

        $this->svc()->approve($r2, $admin, true);

        $this->assertSame('superseded', $r1->fresh()->state);
        $old = Template::withoutGlobalScopes()->find($copy1->id);
        $this->assertSame('superseded', $old->release_status);
        $this->assertNotNull($old->archived_at, 'hidden from new documents');
        $this->assertSame($tplBefore, $old->only(['cds_json', 'editor_state', 'name', 'signing_parties']), 'the old version is never edited');
        $this->assertEquals($docBefore, \DB::table('documents')->where('id', $doc->id)->first(), 'a document already sent is byte-identical');
        $this->assertSame('live', $copy2->fresh()->release_status);
        $this->assertSame(1, TemplateReleaseEvent::where('release_id', $r1->id)->where('event', 'superseded')->count());
    }

    // ── lease link at approval ────────────────────────────────────────────────

    private function linkedSource(\App\Models\Agency $workshop, \App\Models\User $owner): Template
    {
        $t = $this->megaShaped($workshop, $owner);
        RentalLeaseTemplate::withoutGlobalScopes()->create([
            'agency_id' => $workshop->id, 'name' => 'Mega Lease 2026', 'docuperfect_template_id' => $t->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true,
            'field_map' => ['rent' => 'monthly_rental', 'start_date' => 'lease_start', 'tenant_name' => 'tenant_name', 'landlord_name' => 'landlord_name', '_meta' => ['month_to_month' => true]],
        ]);

        return $t;
    }

    public function test_the_lease_link_is_carried_on_the_release_and_applied_only_at_approval(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->linkedSource($w, $owner);
        [$release, $copy] = $this->releasedCopy($t, $client, $owner);

        $this->assertSame('residential', $release->lease_link['category']);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count(), 'nothing linked before approval');

        $result = $this->svc()->approve($release, $this->agencyAdmin($client), true);
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $copy->id, (int) $row->docuperfect_template_id);
        $this->assertSame('monthly_rental', $row->field_map['rent']);
        $this->assertStringContainsString('lease agreement', $result['lease_message']);
        $this->assertSame(1, TemplateReleaseEvent::where('release_id', $release->id)->where('event', 'lease_linked')->count());
    }

    public function test_a_newer_version_moves_the_agencys_lease_link_and_a_foreign_link_is_left_alone(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $other = $this->agency('Own Lease Agency');
        $owner = $this->owner();
        $t = $this->linkedSource($w, $owner);
        $adminC = $this->agencyAdmin($client);

        [$r1, $c1] = $this->releasedCopy($t, $client, $owner);
        $this->svc()->approve($r1, $adminC, true);
        $cds = $t->fresh()->cds_json;
        $cds['title'] = 'v2';
        $t->update(['cds_json' => $cds]);
        $this->svc()->verify($t->fresh(), $owner, true);
        $r2 = $this->svc()->release($t->fresh(), $client, $owner);
        $c2 = Template::withoutGlobalScopes()->find($r2->template_id);
        $this->trackBlade($c2);
        $this->svc()->approve($r2, $adminC, true);
        $this->assertSame((int) $c2->id, (int) RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->value('docuperfect_template_id'), 'the link moved to v2');
        $this->assertSame(1, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count());

        // An agency that already uses a lease agreement of its own keeps it.
        $own = $this->megaShaped($other, $owner, ['name' => 'Their own lease']);
        RentalLeaseTemplate::withoutGlobalScopes()->create(['agency_id' => $other->id, 'name' => 'Their own lease', 'docuperfect_template_id' => $own->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true, 'field_map' => ['rent' => 'x']]);
        $t2 = $this->linkedSource($w, $owner);
        [$r3] = $this->releasedCopy($t2, $other, $owner);
        $res = $this->svc()->approve($r3, $this->agencyAdmin($other), true);
        $this->assertStringContainsString('already uses another lease agreement', $res['lease_message']);
        $this->assertSame((int) $own->id, (int) RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $other->id)->value('docuperfect_template_id'));
    }

    public function test_a_lease_link_that_fails_the_guard_does_not_block_the_approval(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->linkedSource($w, $owner);
        [$release] = $this->releasedCopy($t, $client, $owner);
        // The carried map turns out to be incomplete by the time the agency approves (start date and names missing).
        $release->forceFill(['lease_link' => ['category' => 'residential', 'name' => 'Mega Lease 2026', 'is_default' => true, 'field_map' => ['rent' => 'monthly_rental']]])->save();

        $res = $this->svc()->approve($release, $this->agencyAdmin($client), true);
        $this->assertSame('live', $res['release']->state);
        $this->assertStringContainsString('could not be linked', $res['lease_message']);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count());
    }
}
