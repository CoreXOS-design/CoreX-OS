<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementConflict;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementVersions;
use App\Services\PlatformEsign\Agreement\WordingInvalid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wording safety that needs the database (audit E1, E2, E5, E6, E7, E10, E11): the public page, the publish form, the version rules,
 * the preview throttle and the proof command's guards.
 */
class WordingSafetyTest extends TestCase
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

    private function editedDraft(User $owner, string $marker = 'EDITED-CLAUSE-MARKER'): WordingVersion
    {
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $clauses = $svc->clauses($draft, 'part_b');
        $i = collect($clauses)->search(fn ($c) => str_contains($c, 'This agreement is between'));
        $clauses[$i] = str_replace('This agreement is between', $marker . ' This agreement is between', $clauses[$i]);
        $svc->saveSection($draft, 'part_b', $clauses, (int) $draft->rev, $owner->id);

        return $draft->fresh();
    }

    /** What an owner would see for a refused publish. */
    private function publishError(WordingVersion $draft, string $version, string $date, int $userId, string $note = 'A real change note'): string
    {
        try {
            $this->versions()->publish($draft->fresh(), $version, $date, $note, $userId);
        } catch (WordingInvalid $e) {
            return implode(' ', $e->errors);
        }

        return '';
    }

    // ── E1: the public page ─────────────────────────────────────────────────

    public function test_a_published_version_carrying_hostile_markup_is_rendered_harmless_on_the_public_page(): void
    {
        $v1 = app(AgreementContent::class)->ensureSeeded();
        $content = $v1->content_json;
        $content['part_b'] .= "\n\n<p><img/src=x/onerror=alert(1)></p>\n\n<div style=\"position:fixed;inset:0\"onmouseover=\"alert(document.cookie)\">overlay</div>\n\n"
            . "<p><a href=\"&#x6A;avascript:alert(1)\">click me</a></p>\n\n<p><img src=\"http://169.254.169.254/latest/meta-data/\"></p>\n\nTAIL-OF-LEGAL-TEXT";
        // Straight into the table (bypassing the editor's checks) — this is the worst case: bad text that is already immutable and published.
        WordingVersion::create(['template_id' => $v1->template_id, 'version' => '1.1', 'version_date' => '2026-11-02', 'content_json' => $content,
            'rates_json' => $v1->rates_json, 'is_published' => true, 'published_at' => now()->addMinute(), 'change_note' => 'x']);

        $res = $this->get('/legal')->assertOk()->assertSee('TAIL-OF-LEGAL-TEXT')->assertSee('overlay')->assertSee('click me');
        $body = $res->getContent();
        foreach (['onerror', 'onmouseover', 'javascript', '169.254', 'position:fixed', 'inset:0', 'alert(', '<img src="http://'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $body, $bad . ' reached the public page');
        }
    }

    public function test_the_editor_refuses_hostile_wording_with_a_plain_message_and_the_live_preview_cannot_run_it(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->versions()->createDraft($owner->id);
        $clauses = $this->versions()->clauses($draft, 'part_c');
        $clauses[] = '<p><img/src=x/onerror=alert(1)></p>';

        $res = $this->actingAs($owner)->putJson(route('platform-esign.wording.section.save', [$draft->id, 'part_c']), ['clauses' => $clauses, 'rev' => 0])->assertStatus(422);
        $this->assertStringContainsString('cannot be used', implode(' ', $res->json('errors')));
        $this->assertSame(0, (int) $draft->fresh()->rev, 'nothing was saved');

        // The /render endpoint feeds x-html on every keystroke: even before any save check, the payload comes back inert.
        $html = $this->actingAs($owner)->postJson(route('platform-esign.wording.render', $draft->id), ['md' => '<p><a href="java&#x0A;script:alert(1)" onclick="x()">go</a></p><img src=x onerror=alert(1)>'])
            ->assertOk()->json('html');
        $this->assertStringNotContainsStringIgnoringCase('script:', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringContainsString('go', $html);
    }

    public function test_the_public_page_ships_a_content_security_policy_that_matches_its_one_inline_script(): void
    {
        $res = $this->get('/legal')->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        foreach (["default-src 'none'", "style-src 'unsafe-inline'", "img-src 'self' data:", "base-uri 'none'", "form-action 'none'", "frame-ancestors 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", $csp);
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));

        // The print button's script is allowed by hash, nothing else: the hash in the header is the hash of the script in the page.
        $this->assertSame(1, preg_match_all('#<script>(.*?)</script>#s', $res->getContent(), $m), 'exactly one inline script');
        $this->assertStringContainsString("script-src 'sha256-" . base64_encode(hash('sha256', $m[1][0], true)) . "'", $csp);
        $this->assertStringNotContainsString('onclick=', $res->getContent(), 'no inline event handler left on the page');

        $etag = $res->headers->get('ETag');
        $again = $this->get('/legal', ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame($csp, $again->headers->get('Content-Security-Policy'), 'the revalidation answer carries the same policy');
    }

    // ── E11: no write on an anonymous GET, graceful failure ─────────────────

    public function test_the_first_visit_seeds_once_and_later_visits_write_nothing(): void
    {
        $this->assertSame(0, Template::where('name', AgreementContent::TEMPLATE_NAME)->count());
        $this->get('/legal')->assertOk();
        $this->assertSame(1, Template::where('name', AgreementContent::TEMPLATE_NAME)->count());
        $this->assertSame(1, WordingVersion::count());

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->get('/legal')->assertOk();
        $this->get('/legal/v/1.0')->assertRedirect();
        $this->assertSame([], array_values(array_filter($writes, fn ($s) => str_contains($s, 'platform_esign'))), 'a public GET must not write wording rows');

        app(AgreementContent::class)->ensureSeeded();
        app(AgreementContent::class)->ensureSeeded();
        $this->assertSame(1, Template::where('name', AgreementContent::TEMPLATE_NAME)->count(), 'seeding is idempotent');
        $this->assertSame(1, WordingVersion::count());
    }

    public function test_a_missing_wording_table_answers_503_not_a_stack_trace(): void
    {
        Schema::rename('platform_esign_templates', 'platform_esign_templates_gone');
        try {
            $this->get('/legal')->assertStatus(503);
            $this->get('/legal/v/1.0')->assertStatus(503);
        } finally {
            Schema::rename('platform_esign_templates_gone', 'platform_esign_templates');
        }
    }

    public function test_every_writing_route_is_closed_to_a_non_owner(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->versions()->createDraft($owner->id);
        $agent = User::factory()->create(['role' => 'agent']);
        $id = $draft->id;

        $this->actingAs($agent)->putJson(route('platform-esign.wording.section.save', [$id, 'part_b']), ['clauses' => ['x'], 'rev' => 0])->assertForbidden();
        $this->actingAs($agent)->putJson(route('platform-esign.wording.rates.save', $id), ['rev' => 0, 'rates' => []])->assertForbidden();
        $this->actingAs($agent)->postJson(route('platform-esign.wording.render', $id), ['md' => 'x'])->assertForbidden();
        $this->actingAs($agent)->post(route('platform-esign.wording.publish', $id), ['version' => '1.1', 'version_date' => now()->toDateString(), 'change_note' => 'sneaky change', 'rev' => 0])->assertForbidden();
        $this->actingAs($agent)->post(route('platform-esign.wording.discard', $id))->assertForbidden();
        $this->actingAs($agent)->post(route('platform-esign.wording.restore', $id))->assertForbidden();
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame(0, (int) $draft->fresh()->rev);
        $this->assertSame(1, WordingVersion::published()->count());
    }

    // ── E5: publish is bound to the revision the owner reviewed ─────────────

    public function test_publish_refuses_a_draft_that_changed_after_the_owner_looked_at_it(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->editedDraft($owner, 'REVIEWED-TEXT'); // rev 1
        $svc = $this->versions();

        try {
            $svc->publish($draft, '1.1', now()->toDateString(), 'A real change note', $owner->id, 0); // the owner's screen showed rev 0
            $this->fail('a stale review was published');
        } catch (AgreementConflict $e) {
            $this->assertSame(1, $e->rev);
        }
        $this->assertSame(1, WordingVersion::published()->count());
        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_the_publish_form_carries_the_revision_and_the_controller_checks_it(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->editedDraft($owner, 'FORM-REV-TEXT'); // rev 1

        $page = $this->actingAs($owner)->get(route('platform-esign.wording.show', $draft->id))->assertOk()->getContent();
        $form = substr($page, strpos($page, route('platform-esign.wording.publish', $draft->id)));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertStringContainsString('name="rev" value="1"', $form, 'the publish form carries the reviewed revision');

        $post = ['version' => '1.1', 'version_date' => now()->toDateString(), 'change_note' => 'Reworded one sentence for the test.'];
        $this->actingAs($owner)->post(route('platform-esign.wording.publish', $draft->id), $post)->assertSessionHasErrors('rev');
        $this->actingAs($owner)->post(route('platform-esign.wording.publish', $draft->id), $post + ['rev' => 0])->assertSessionHasErrors('publish');
        $this->assertSame(1, WordingVersion::published()->count(), 'neither refused request published anything');

        $this->actingAs($owner)->post(route('platform-esign.wording.publish', $draft->id), $post + ['rev' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('1.1', AgreementContent::current()->version);
    }

    // ── E6: version number + date rules ─────────────────────────────────────

    public function test_version_numbers_have_one_spelling_only_go_up_and_dates_cannot_run_ahead(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $draft = $this->editedDraft($owner, 'VERSION-RULES-TEXT');
        $today = now()->toDateString();

        $this->assertStringContainsString('Write the version number as 1.0', $this->publishError($draft, '1.00', $today, $owner->id));
        $this->assertStringContainsString('Write the version number as 1.0', $this->publishError($draft, '01.0', $today, $owner->id));
        $this->assertStringContainsString('Write the version number as 1.0', $this->publishError($draft, '1.0.0', $today, $owner->id));
        $this->assertStringContainsString('already exists', $this->publishError($draft, '1.0', $today, $owner->id));
        $this->assertStringContainsString('cannot be more than 30 days in the future', $this->publishError($draft, '1.1', now()->addDays(60)->toDateString(), $owner->id));
        $this->assertStringContainsString('cannot be more than 30 days', $this->publishError($draft, '1.1', now()->addYear()->toDateString(), $owner->id));
        $this->assertSame(1, WordingVersion::published()->count());

        $pub = $this->versions()->publish($draft->fresh(), '1.2', now()->addDays(10)->toDateString(), 'Published a little ahead of its date.', $owner->id);
        $this->assertSame('1.2', $pub->version);

        // Going backwards is refused: 1.1 and 1.0.5 are both lower than the latest published 1.2.
        $next = $this->editedDraft($owner, 'SECOND-VERSION-RULES-TEXT');
        $this->assertStringContainsString('not higher than the latest published version (1.2)', $this->publishError($next, '1.1', $today, $owner->id));
        $this->assertStringContainsString('not higher than the latest published version (1.2)', $this->publishError($next, '1.0.5', $today, $owner->id));
        $this->assertStringContainsString('already exists', $this->publishError($next, '1.2', $today, $owner->id), 'the same number is refused too');
        $this->assertSame(2, WordingVersion::published()->count());
    }

    // ── E3 through the publish gate ─────────────────────────────────────────

    public function test_a_rates_change_that_contradicts_the_wording_blocks_publishing_with_a_plain_message(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $svc = $this->versions();
        $draft = $svc->createDraft($owner->id);
        $rates = array_map(fn ($x) => (string) $x, array_merge($draft->rates_json, ['agency_t1_max' => 15, 'agency_t1' => 320]));
        $svc->saveRates($draft, $rates, 0, $owner->id);

        $problems = implode(' | ', $svc->problems($draft->fresh()));
        $this->assertStringContainsString('seats 1 to 10', $problems);
        $this->assertStringContainsString('worked example', $problems);
        $err = $this->publishError($draft, '1.1', now()->toDateString(), $owner->id);
        $this->assertStringContainsString('first tier ending at seat 15', $err);
        $this->assertSame(1, WordingVersion::published()->count());

        $this->actingAs($owner)->get(route('platform-esign.wording.show', $draft->id))->assertOk()->assertSee('Fix before publishing');
    }

    // ── E7: calibration endpoints are throttled and never rewrite a published layout ───

    public function test_preview_and_sample_pdf_are_throttled_per_owner(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $key = 'wording-calibrate:preview:' . $owner->id;
        RateLimiter::clear($key);
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit($key, 60);
        }
        $this->actingAs($owner)->get(route('platform-esign.wording.preview', $v1->id))->assertStatus(429);
        $this->actingAs($owner)->get(route('platform-esign.wording.preview.pdf', $v1->id))->assertStatus(429);
        RateLimiter::clear($key);
    }

    public function test_previewing_a_published_version_never_rewrites_its_stored_layout(): void
    {
        $owner = $this->owner();
        $v1 = app(AgreementContent::class)->ensureSeeded($owner->id);
        $this->assertNull($v1->fresh()->layout_json);
        $this->actingAs($owner)->get(route('platform-esign.wording.preview', $v1->id))->assertOk();
        $this->assertNull($v1->fresh()->layout_json, 'a preview must not write the layout of a published version that agreements are pinned to');
        RateLimiter::clear('wording-calibrate:preview:' . $owner->id);
    }

    // ── E10: the proof command's guards ─────────────────────────────────────

    private function realAgreement(User $owner): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@realagency.test', 'cell' => '+27 82 555 0123', 'take_on_month' => now()->format('Y-m')], $owner->id);
    }

    public function test_seal_and_cleanup_refuse_a_real_agreement(): void
    {
        $owner = $this->owner();
        $doc = $this->realAgreement($owner);

        foreach (['--seal', '--cleanup'] as $flag) {
            $exit = Artisan::call('platform-esign:verify-wording', ['--doc' => $doc->id, $flag => true]);
            $out = Artisan::output();
            $this->assertSame(1, $exit, $out);
            $this->assertStringContainsString('not a throwaway', $out);
            $this->assertStringContainsString('wording-proof@example.test', $out);
        }
        $fresh = Document::withoutGlobalScopes()->find($doc->id);
        $this->assertSame($doc->status, $fresh->status, 'the real agreement was not signed, voided or deleted');
        $this->assertNull($fresh->deleted_at);
        $this->assertFalse(in_array($fresh->status, ['completed', 'voided'], true));
    }

    public function test_a_throwaway_made_by_prepare_is_accepted_and_retired_by_cleanup(): void
    {
        $owner = $this->owner();
        $exit = Artisan::call('platform-esign:verify-wording', ['--prepare' => true, '--user' => $owner->id]);
        $out = Artisan::output(); // read once: the buffer empties when fetched
        $this->assertSame(0, $exit, $out);
        preg_match('/DOC_ID=(\d+)/', $out, $m);
        $id = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $id, $out);

        Artisan::call('platform-esign:verify-wording', ['--doc' => $id, '--cleanup' => true]);
        $out = Artisan::output();
        $this->assertStringNotContainsString('Refused', $out, $out);
        $this->assertSame('voided', Document::withoutGlobalScopes()->find($id)->status, 'the throwaway was retired');
    }

    public function test_prepare_seal_and_cleanup_never_run_in_production(): void
    {
        $owner = $this->owner();
        $doc = $this->realAgreement($owner);
        $before = Document::withoutGlobalScopes()->count();
        $this->app['env'] = 'production';
        try {
            foreach ([['--prepare' => true, '--user' => $owner->id], ['--doc' => $doc->id, '--seal' => true], ['--doc' => $doc->id, '--cleanup' => true]] as $args) {
                $exit = Artisan::call('platform-esign:verify-wording', $args);
                $out = Artisan::output();
                $this->assertSame(1, $exit, $out);
                $this->assertStringContainsString('never run in production', $out);
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($before, Document::withoutGlobalScopes()->count(), 'no agreement was created');
        $this->assertSame($doc->status, Document::withoutGlobalScopes()->find($doc->id)->status);
    }
}
