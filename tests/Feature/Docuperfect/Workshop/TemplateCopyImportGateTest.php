<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\RentalLeaseTemplate;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Johan's rule "an agency must approve a template before it is live" applies to EVERY way a template reaches an agency: the release
 * screen, "Copy to another agency", "Import package" (screen and templates:import). Each lands as a release awaiting approval, with a log
 * row, hidden from every picker until a person the agency chose approves it. No owner override (spec esign-template-transfer.md §13.1, §13.3).
 */
final class TemplateCopyImportGateTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private const MAP = ['rent' => 'monthly_rental', 'start_date' => 'lease_start', 'tenant_name' => 'tenant_name', 'landlord_name' => 'landlord_name', '_meta' => ['month_to_month' => true]];

    private function copyTo(\App\Models\User $owner, Template $src, \App\Models\Agency $target, array $extra = [])
    {
        return $this->actingAs($owner)->post(route('docuperfect.templates.copyToAgency', $src->id), ['agency_id' => $target->id] + $extra);
    }

    private function releaseFor(\App\Models\Agency $agency): TemplateRelease
    {
        $r = TemplateRelease::withoutGlobalScopes()->where('agency_id', $agency->id)->orderByDesc('id')->firstOrFail();
        $this->trackBlade(Template::withoutGlobalScopes()->find($r->template_id));

        return $r;
    }

    private function linked(\App\Models\Agency $agency, \App\Models\User $owner): Template
    {
        $t = $this->megaShaped($agency, $owner);
        RentalLeaseTemplate::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'name' => 'Lease 2026', 'docuperfect_template_id' => $t->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true, 'field_map' => self::MAP]);

        return $t;
    }

    // ── Copy to another agency ───────────────────────────────────────────────

    public function test_a_copy_lands_awaiting_approval_not_live_with_a_log_row(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->megaShaped($w, $owner);

        $resp = $this->copyTo($owner, $src, $client);
        $resp->assertRedirect()->assertSessionHas('status');
        $this->assertStringContainsString('NOT live', (string) session('status'));

        $release = $this->releaseFor($client);
        $copy = Template::withoutGlobalScopes()->find($release->template_id);
        $this->assertSame('awaiting_approval', $release->state);
        $this->assertSame('awaiting_approval', $copy->release_status);
        $this->assertSame($client->id, (int) $copy->agency_id);
        $this->assertSame('copy', TemplateReleaseEvent::where('release_id', $release->id)->where('event', 'released')->first()->detail['via']);
        $this->assertFalse(Template::active()->visibleTo($this->agencyAdmin($client))->whereKey($copy->id)->exists(), 'hidden from the agency until approved');
        $this->assertNull($src->fresh()->release_status, 'the source is untouched');
        $this->assertSame(1, TemplateTransferLog::where('direction', 'copy')->where('target_agency_id', $client->id)->count(), 'the transfer log still records the copy');
    }

    public function test_the_agency_approves_a_copy_and_only_then_is_it_live(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $this->copyTo($owner, $this->megaShaped($w, $owner), $client);
        $release = $this->releaseFor($client);
        $svc = app(TemplateReleaseService::class);

        try {
            $svc->approve($release, $owner, true);
            $this->fail('the CoreX owner cannot approve for an agency — there is no override');
        } catch (TemplateReleaseException $e) {
            $this->assertSame('awaiting_approval', $release->fresh()->state);
        }

        $res = $svc->approve($release, $this->agencyAdmin($client), true);
        $this->assertSame('live', $res['release']->state);
        $this->assertTrue(Template::active()->visibleTo($this->agencyAdmin($client))->whereKey($release->template_id)->exists());
    }

    public function test_the_lease_link_travels_only_when_ticked_and_is_applied_only_at_approval(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->linked($w, $owner);

        $this->copyTo($owner, $src, $client, ['copy_lease_field_map' => 1])->assertSessionHas('status');
        $release = $this->releaseFor($client);
        $this->assertSame('residential', $release->lease_link['category']);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count(), 'no link exists while the copy waits');

        app(TemplateReleaseService::class)->approve($release, $this->agencyAdmin($client), true);
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->first();
        $this->assertSame((int) $release->template_id, (int) $row->docuperfect_template_id);
        $this->assertSame('monthly_rental', $row->field_map['rent']);
    }

    public function test_a_copy_without_the_lease_tick_carries_no_link(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();

        $this->copyTo($owner, $this->linked($w, $owner), $client);
        $this->assertNull($this->releaseFor($client)->lease_link);
    }

    public function test_a_second_copy_while_the_first_waits_and_a_copy_to_its_own_agency_are_refused(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->megaShaped($w, $owner);

        $this->copyTo($owner, $src, $client)->assertSessionHas('status');
        $this->copyTo($owner, $src, $client)->assertSessionHasErrors('copy_to_agency');
        $this->assertStringContainsString('already waiting', session('errors')->first('copy_to_agency'));
        $this->assertSame(1, TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->count());

        $this->copyTo($owner, $src, $w)->assertSessionHasErrors('copy_to_agency');
        $this->assertSame(0, TemplateRelease::withoutGlobalScopes()->where('agency_id', $w->id)->count());
    }

    public function test_an_agency_user_cannot_copy_at_all(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $src = $this->megaShaped($w, $owner);

        $this->actingAs($this->agencyAdmin($w))->postJson(route('docuperfect.templates.copyToAgency', $src->id), ['agency_id' => $client->id])->assertForbidden();
        $this->assertSame(0, TemplateRelease::withoutGlobalScopes()->count());
    }

    // ── Import package (screen) ──────────────────────────────────────────────

    private function uploadToken(\App\Models\User $owner, string $bytes): string
    {
        $resp = $this->actingAs($owner)->post(route('docuperfect.template-transfer.upload'), ['package' => UploadedFile::fake()->createWithContent('pkg.cxpkg', $bytes)]);
        $this->assertTrue($resp->isRedirect());
        preg_match('#/preview/([A-Za-z0-9]{40})#', (string) $resp->headers->get('Location'), $m);

        return $m[1];
    }

    public function test_an_import_from_the_screen_lands_awaiting_approval_and_says_so(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $token = $this->uploadToken($owner, $this->export($this->linked($w, $owner), $owner)['bytes']);

        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', ['token' => $token, 'agency_id' => $client->id]))
            ->assertOk()->assertSee('NOT be live')->assertSee('Send for approval');

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $client->id, 'confirmed' => 1])
            ->assertRedirect(route('docuperfect.template-transfer.index'))->assertSessionHas('status');
        $this->assertStringContainsString('Not live there yet', (string) session('status'));

        $release = $this->releaseFor($client);
        $copy = Template::withoutGlobalScopes()->find($release->template_id);
        $this->assertSame('awaiting_approval', $release->state);
        $this->assertSame('awaiting_approval', $copy->release_status);
        $this->assertSame('package', TemplateReleaseEvent::where('release_id', $release->id)->where('event', 'released')->first()->detail['via']);
        $this->assertFalse(Template::active()->visibleTo($this->agencyAdmin($client))->whereKey($copy->id)->exists());
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count(), 'the carried lease link waits for approval too');
        $this->assertSame(1, TemplateTransferLog::where('direction', 'import')->where('target_agency_id', $client->id)->count());
    }

    public function test_an_import_with_a_name_clash_honours_the_choice_and_replaces_nothing(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->megaShaped($w, $owner), $owner)['bytes'];

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $this->uploadToken($owner, $bytes)), ['agency_id' => $client->id, 'confirmed' => 1])->assertSessionHas('status');
        $first = $this->releaseFor($client);

        $token = $this->uploadToken($owner, $bytes);
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $client->id, 'confirmed' => 1])->assertSessionHasErrors('package');
        $this->assertSame(1, TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->count(), 'a clash with no choice creates nothing');

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $client->id, 'confirmed' => 1, 'choice' => [0 => 'new_copy']])->assertSessionHas('status');
        $second = $this->releaseFor($client);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame(Template::withoutGlobalScopes()->find($first->template_id)->name, Template::withoutGlobalScopes()->find($second->template_id)->name);
        $this->assertSame('awaiting_approval', $first->fresh()->state, 'the first one is untouched');
    }

    public function test_a_bundle_with_one_unresolved_clash_creates_nothing(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->megaShaped($w, $owner), $owner)['bytes'];
        $loaded = app(TemplatePackageImporter::class)->load($this->tmpFile($bytes));
        $svc = app(TemplateReleaseService::class);

        $svc->releasePackages($loaded, $client, $owner, [], false);
        $this->assertSame(1, TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->count());
        $this->trackBlade(Template::withoutGlobalScopes()->find(TemplateRelease::withoutGlobalScopes()->first()->template_id));

        try {
            $svc->releasePackages(array_merge($loaded, $loaded), $client, $owner, [], false);
            $this->fail('the clash has no choice, so the whole bundle is refused');
        } catch (TemplateReleaseException $e) {
            $this->assertSame(1, TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->count());
        }
    }
}
