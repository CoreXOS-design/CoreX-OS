<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\Agency;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WordingAudit;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementBlocks;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementTokens;
use App\Services\PlatformEsign\Agreement\AgreementVersions;
use App\Services\PlatformEsign\Agreement\WordingInvalid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subscription Agreement wording editor + versions — spec §11.14. Drafts, clause-level saves, field-marker guard,
 * immutability, publish, pinning of sent agreements, restore-by-copy, discard/restore, what changed, owner-only.
 */
class WordingVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function versions(): AgreementVersions
    {
        return app(AgreementVersions::class);
    }

    /** A draft whose Part B clause 1.1 reads differently (a throwaway edit). */
    private function editedDraft(User $owner, string $marker = 'EDITED-CLAUSE-MARKER'): WordingVersion
    {
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $clauses = $svc->clauses($draft, 'part_b');
        $i = collect($clauses)->search(fn ($c) => str_contains($c, 'This agreement is between'));
        $this->assertNotFalse($i);
        $clauses[$i] = str_replace('This agreement is between', $marker . ' This agreement is between', $clauses[$i]);
        $svc->saveSection($draft, 'part_b', $clauses, (int) $draft->rev, $owner->id);

        return $draft->fresh();
    }

    // ── clause splitting + markers (no DB writes needed beyond seeding) ────

    public function test_clause_split_then_join_is_lossless_for_every_part_of_version_one(): void
    {
        $r = app(AgreementRenderer::class);
        foreach ((new AgreementContent)->buildV1() as $part => $md) {
            $blocks = AgreementBlocks::split($md);
            $this->assertSame($r->markdownBlocks($md), $r->markdownBlocks(AgreementBlocks::join($blocks)), $part . ' changed after split/join');
            $this->assertSame(trim($md), AgreementBlocks::join($blocks), $part . ' is not byte-identical after split/join');
        }
    }

    public function test_version_one_passes_every_marker_rule(): void
    {
        foreach ((new AgreementContent)->buildV1() as $part => $md) {
            $this->assertSame([], AgreementTokens::validate($md, \App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES, $part), $part);
            $this->assertSame([], AgreementTokens::compare($md, $md, $part));
        }
    }

    // ── access ─────────────────────────────────────────────────────────────

    public function test_every_wording_screen_is_owner_only(): void
    {
        $owner = $this->owner();
        $v = app(AgreementContent::class)->ensureSeeded($owner->id);
        $agent = User::factory()->create(['role' => 'agent']);

        $gets = [route('platform-esign.wording.index'), route('platform-esign.wording.show', $v->id), route('platform-esign.wording.preview', $v->id),
            route('platform-esign.wording.compare'), route('platform-esign.wording.preview.pdf', $v->id)];
        foreach ($gets as $url) {
            $this->get($url)->assertRedirect();                       // guest → login
        }
        foreach ($gets as $url) {
            $this->actingAs($agent)->get($url)->assertForbidden();   // any non-owner
        }
        $this->actingAs($agent)->post(route('platform-esign.wording.draft.create'))->assertForbidden();
        $this->actingAs($agent)->post(route('platform-esign.wording.settings'), [])->assertForbidden();
        foreach ($gets as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
        $this->assertSame(0, WordingVersion::drafts()->count());
    }

    // ── drafts ─────────────────────────────────────────────────────────────

    public function test_new_version_copies_the_current_one_and_only_one_draft_is_allowed(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->versions()->createDraft($owner->id);

        $this->assertFalse($draft->is_published);
        $this->assertSame($v1->content_json, $draft->content_json);
        $this->assertSame($v1->rates_json, $draft->rates_json);
        $this->assertSame($v1->id, $draft->parent_version_id);
        $this->assertStringStartsWith('draft-', $draft->version);
        $this->expectException(\DomainException::class);
        $this->versions()->createDraft($owner->id);
    }

    public function test_saving_a_section_changes_only_the_draft_and_is_audited(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $before = $v1->content_json;
        $draft = $this->editedDraft($owner);

        $this->assertStringContainsString('EDITED-CLAUSE-MARKER', $draft->content_json['part_b']);
        $this->assertSame($before, $v1->fresh()->content_json, 'the published version must not change');
        $this->assertSame(1, $draft->rev);
        $this->assertNull($draft->layout_json);
        $a = WordingAudit::where('action', 'section_saved')->first();
        $this->assertSame($owner->id, $a->user_id);
        $this->assertStringContainsString('1 edited', $a->detail);
    }

    public function test_a_stale_revision_is_refused_instead_of_overwriting_another_windows_save(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $clauses = $svc->clauses($draft, 'part_d');
        $svc->saveSection($draft, 'part_d', $clauses, 0, $owner->id);
        $this->expectException(\App\Services\PlatformEsign\Agreement\AgreementConflict::class);
        $svc->saveSection($draft, 'part_d', $clauses, 0, $owner->id);
    }

    public function test_field_markers_cannot_be_removed_duplicated_invented_or_damaged_and_scripts_are_refused(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $clauses = $svc->clauses($draft, 'part_a');
        $joined = AgreementBlocks::join($clauses);
        $this->assertStringContainsString('{{f:reg_no}}', $joined);

        $try = function (string $md) use ($svc, $draft, $owner): array {
            try {
                $svc->saveSection($draft->fresh(), 'part_a', [$md], (int) $draft->fresh()->rev, $owner->id);
            } catch (WordingInvalid $e) {
                return $e->errors;
            }

            return [];
        };
        $this->assertStringContainsString('Registration number', implode(' ', $try(str_replace('{{f:reg_no}}', '', $joined))), 'removing a field');
        $this->assertStringContainsString('2 times', implode(' ', $try($joined . "\n\n{{f:reg_no}}")), 'duplicating a field');
        $this->assertStringContainsString('not one the agreement knows', implode(' ', $try($joined . "\n\n{{f:made_up}}")), 'inventing a field');
        $this->assertStringContainsString('damaged', implode(' ', $try(str_replace('{{f:reg_no}}', '{{f:reg_no}', $joined))), 'damaging a marker');
        $this->assertStringContainsString('cannot be used', implode(' ', $try($joined . "\n\n<script>alert(1)</script>")), 'script');
        $this->assertStringContainsString('cannot be used', implode(' ', $try($joined . "\n\n<p onclick=\"x()\">hi</p>")), 'event handler');
        $this->assertSame(0, (int) $draft->fresh()->rev, 'nothing may have been saved');

        // Rewriting the words AROUND a marker is fine, and so is moving it.
        $ok = str_replace('Sign-up Form', 'Sign-up Form (reworded)', $joined);
        $this->assertSame([], $try($ok));
    }

    // ── immutability ───────────────────────────────────────────────────────

    public function test_a_published_version_is_immutable_and_can_never_be_deleted(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);

        foreach (['content_json' => ['part_b' => 'x'], 'rates_json' => ['agency_base' => 1], 'version' => '9.9', 'change_note' => 'x'] as $col => $val) {
            try {
                $v1->update([$col => $val]);
                $this->fail($col . ' of a published version was changed');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $v1 = $v1->fresh();
        $v1->forceFill(['layout_json' => ['rev' => 1, 'parts' => ['intro' => [1]], 'total' => 1]])->save(); // pagination may be recalculated
        try {
            $v1->delete();
            $this->fail('a published version was deleted');
        } catch (\LogicException) {
        }
        $this->assertNotNull(WordingVersion::find($v1->id));
    }

    // ── publish ────────────────────────────────────────────────────────────

    public function test_publish_rules_then_a_successful_publish_makes_it_current_and_immutable(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $this->editedDraft($owner, 'TEST-ONLY-EDIT');
        $rules = fn (string $ver, string $date, string $note) => (function () use ($svc, $draft, $ver, $date, $note, $owner) {
            try {
                $svc->publish($draft->fresh(), $ver, $date, $note, $owner->id);
            } catch (WordingInvalid $e) {
                return implode(' ', $e->errors);
            }

            return '';
        })();

        $this->assertStringContainsString('1.1', $svc->nextVersion());
        $this->assertStringContainsString('already exists', $rules('1.0', now()->toDateString(), 'A real note here'));
        $this->assertStringContainsString('must look like', $rules('one', now()->toDateString(), 'A real note here'));
        $this->assertStringContainsString('change note', $rules('1.1', now()->toDateString(), 'no'));
        $this->assertStringContainsString('valid version date', $rules('1.1', '2026-13-45', 'A real note here'));
        $this->assertSame(1, WordingVersion::published()->count(), 'a refused publish must publish nothing');

        $pub = $svc->publish($draft->fresh(), '1.1', '2026-10-06', 'TEST VERSION — one throwaway sentence changed.', $owner->id);
        $this->assertTrue($pub->is_published);
        $this->assertSame('1.1', $pub->version);
        $this->assertSame($owner->id, $pub->published_by);
        $this->assertNotEmpty($pub->layout_json['parts'] ?? null, 'pagination is calibrated at publish');
        $this->assertSame($pub->id, AgreementContent::current()->id);
        $this->assertSame($pub->id, app(AgreementContent::class)->ensureSeeded()->id, 'a new agreement pins the CURRENT version');
        $this->assertSame($v1->id, WordingVersion::where('version', '1.0')->value('id'));
        $this->assertNotNull(WordingAudit::where('action', 'published')->first());
        $this->assertStringContainsString('identical', (function () use ($svc, $owner, $pub) {
            $d = $svc->createDraft($owner->id, $pub->id);
            try {
                $svc->publish($d, '1.2', '2026-10-07', 'Nothing really changed here', $owner->id);
            } catch (WordingInvalid $e) {
                return implode(' ', $e->errors);
            }

            return '';
        })());
        try {
            $pub->update(['change_note' => 'sneaky']);
            $this->fail('published version edited');
        } catch (\LogicException) {
        }
    }

    public function test_a_sent_agreement_stays_on_its_version_while_a_new_one_uses_the_new_version(): void
    {
        $owner = $this->owner();
        Mail::fake();
        $svc = app(AgreementService::class);
        $old = $svc->send(['name' => 'Pat Principal', 'email' => 'pat@throwaway.invalid'], $owner->id);
        $draft = $this->editedDraft($owner, 'NEWER-WORDING-SENTENCE');
        $this->versions()->publish($draft, '1.1', '2026-10-06', 'TEST VERSION — throwaway sentence', $owner->id);
        $new = $svc->send(['name' => 'Sam Signer', 'email' => 'sam@throwaway.invalid'], $owner->id);

        $this->assertNotSame($old->wording_version_id, $new->wording_version_id);
        $this->assertSame('1.0', $old->fresh('wording')->wording->version);
        $this->assertSame('1.1', $new->fresh('wording')->wording->version);

        $tok = fn (Document $d) => Signer::where('document_id', $d->id)->where('role_key', 'r1')->value('token');
        $this->get(route('platform-esign.agreement.show', $tok($old)))->assertOk()->assertSee('Version 1.0 — 28 September 2026')->assertDontSee('NEWER-WORDING-SENTENCE');
        $this->get(route('platform-esign.agreement.show', $tok($new)))->assertOk()->assertSee('Version 1.1 — 6 October 2026')->assertSee('NEWER-WORDING-SENTENCE');
    }

    public function test_an_earlier_wording_is_restored_by_publishing_a_copy_as_a_new_version(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $svc->publish($this->editedDraft($owner), '1.1', '2026-10-06', 'TEST VERSION — to be reverted', $owner->id);

        $restore = $svc->createDraft($owner->id, $v1->id);
        $this->assertSame($v1->content_json, $restore->content_json);
        $v12 = $svc->publish($restore, '1.2', '2026-10-06', 'Restores the exact wording of version 1.0 (1.1 was a test).', $owner->id);

        $this->assertSame($v1->content_json, $v12->content_json);
        $this->assertSame($v1->rates_json, $v12->rates_json);
        $this->assertSame($v12->id, AgreementContent::current()->id);
        $this->assertSame(3, WordingVersion::published()->count(), 'no version is ever removed');
    }

    // ── discard / restore ──────────────────────────────────────────────────

    public function test_discarding_a_draft_is_soft_and_it_can_be_restored(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $this->editedDraft($owner);

        $svc->discard($draft, $owner->id);
        $this->assertNull($svc->draft());
        $this->assertNotNull(WordingVersion::onlyTrashed()->find($draft->id), 'kept, not deleted');
        $second = $svc->createDraft($owner->id); // a discarded draft does not block a new one
        try {
            $svc->restore($draft->id, $owner->id);
            $this->fail('two drafts at once');
        } catch (\DomainException) {
        }
        $svc->discard($second, $owner->id);
        $back = $svc->restore($draft->id, $owner->id);
        $this->assertSame($draft->id, $back->id);
        $this->assertSame($draft->id, $svc->draft()->id);
        $this->assertSame(['draft_created', 'section_saved', 'discarded', 'draft_created', 'discarded', 'restored'], WordingAudit::orderBy('id')->pluck('action')->all());
    }

    // ── rates ──────────────────────────────────────────────────────────────

    public function test_rates_are_validated_saved_and_change_the_fee_calculation_of_that_version_only(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $good = array_map(fn ($x) => (string) $x, array_merge($draft->rates_json, ['agency_base' => 1695, 'agency_t1' => '299.50']));

        $bad = $good;
        $bad['branch'] = 'lots';
        try {
            $svc->saveRates($draft, $bad, 0, $owner->id);
            $this->fail('invalid rate accepted');
        } catch (WordingInvalid $e) {
            $this->assertStringContainsString('Additional branch', implode(' ', $e->errors));
        }
        $bad = $good;
        $bad['agency_t2_max'] = 5;
        try {
            $svc->saveRates($draft, $bad, 0, $owner->id);
            $this->fail('overlapping tiers accepted');
        } catch (WordingInvalid $e) {
            $this->assertStringContainsString('second tier', implode(' ', $e->errors));
        }

        $svc->saveRates($draft, $good, 0, $owner->id);
        $d = $draft->fresh();
        $this->assertEquals(1695, $d->rates_json['agency_base']);
        $this->assertEquals(299.5, $d->rates_json['agency_t1']);
        $this->assertEquals(1495, $v1->fresh()->rates_json['agency_base']);
        $calc = \App\Services\PlatformEsign\Agreement\AgreementPricing::compute('agency', 10, 0, 0.0, $d->rates_json);
        $this->assertEquals(1695 + 10 * 299.5, $calc['total']);
        $this->assertStringContainsString('R1 695', implode(' ', app(AgreementRenderer::class)->blocks($d, 'part_a', 'canon')));
        $this->assertStringContainsString('1 495 → 1 695', (string) WordingAudit::where('action', 'rates_saved')->value('detail'));
    }

    // ── screens ────────────────────────────────────────────────────────────

    public function test_editor_screens_render_and_the_json_endpoints_work(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->versions()->createDraft($owner->id);

        $this->actingAs($owner)->get(route('platform-esign.wording.index'))->assertOk()->assertSee('Continue the draft')->assertSee('Version 1.0');
        $this->actingAs($owner)->get(route('platform-esign.wording.show', [$draft->id, 'part' => 'part_b']))->assertOk()->assertSee('B1. This agreement')->assertSee('Publish this draft');
        $this->actingAs($owner)->get(route('platform-esign.wording.show', [$draft->id, 'part' => 'rates']))->assertOk()->assertSee('Save rates');
        $this->actingAs($owner)->get(route('platform-esign.wording.edit', [$draft->id, 'part_a']))->assertOk()->assertSee('Registration number', false);
        $this->actingAs($owner)->postJson(route('platform-esign.wording.render', $draft->id), ['md' => '**1.1** Hello {{f:reg_no}} {{rate:agency_base}}'])
            ->assertOk()->assertJsonPath('html', fn ($h) => str_contains($h, '<strong>1.1</strong>') && str_contains($h, 'class="tok"') && str_contains($h, 'R1 495'));

        $clauses = $this->versions()->clauses($draft, 'part_c');
        $res = $this->actingAs($owner)->putJson(route('platform-esign.wording.section.save', [$draft->id, 'part_c']), ['clauses' => $clauses, 'rev' => 0])->assertOk();
        $this->assertSame(1, $res->json('rev'));
        $this->actingAs($owner)->putJson(route('platform-esign.wording.section.save', [$draft->id, 'part_c']), ['clauses' => $clauses, 'rev' => 0])->assertStatus(409);
        $this->actingAs($owner)->putJson(route('platform-esign.wording.section.save', [$draft->id, 'part_a']), ['clauses' => ['nothing left'], 'rev' => 1])->assertStatus(422)->assertJsonStructure(['errors']);
        $this->actingAs($owner)->get(route('platform-esign.wording.preview', $draft->id))->assertOk()->assertSee('Draft — not yet published');
        $this->actingAs($owner)->get(route('platform-esign.wording.preview.pdf', $draft->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // A published version has no editor and no render endpoint.
        $v1 = WordingVersion::published()->first();
        $this->actingAs($owner)->get(route('platform-esign.wording.edit', [$v1->id, 'part_b']))->assertNotFound();
        $this->actingAs($owner)->putJson(route('platform-esign.wording.section.save', [$v1->id, 'part_b']), ['clauses' => ['x'], 'rev' => 0])->assertNotFound();
    }

    public function test_what_changed_shows_the_edited_words_side_by_side(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->editedDraft($owner, 'BRAND-NEW-WORDS');
        $res = $this->actingAs($owner)->get(route('platform-esign.wording.compare', ['a' => $v1->id, 'b' => $draft->id, 'only' => 'changes']))->assertOk();
        $res->assertSee('<ins>BRAND-NEW-WORDS</ins>', false)->assertSee('1 clause edited', false);
        $res->assertDontSee('B2. Words we use');
    }

    public function test_settings_are_validated_saved_and_audited(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $good = ['expiry_days' => 45, 'reminder_days' => 2, 'reminder_repeat_days' => 4, 'reminder_max' => 5, 'countersign_reminder_days' => 2];
        $this->actingAs($owner)->post(route('platform-esign.wording.settings'), $good)->assertSessionHasNoErrors();
        $this->assertSame(45, \App\Services\PlatformEsign\Agreement\AgreementSettings::get('expiry_days'));
        $this->assertSame(45, AgreementService::expiryDays());
        $this->assertSame(2, AgreementService::reminderDays());
        $this->assertStringContainsString('Link valid for (days): 30 → 45', WordingAudit::where('action', 'settings_changed')->value('detail'));
        $this->actingAs($owner)->post(route('platform-esign.wording.settings'), array_merge($good, ['expiry_days' => 0]))->assertSessionHasErrors('settings');
        $this->assertSame(45, \App\Services\PlatformEsign\Agreement\AgreementSettings::get('expiry_days'));
    }
}
