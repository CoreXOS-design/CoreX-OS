<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\RentalLeaseTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.9 — templates:export and templates:import (--dry-run writes NOTHING; --release lands awaiting approval).
 */
final class TemplateTransferCommandsTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cxpkg-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function packageFile(Template $t, \App\Models\User $owner): string
    {
        $path = $this->dir . '/' . uniqid() . '.cxpkg';
        file_put_contents($path, $this->export($t, $owner)['bytes']);

        return $path;
    }

    private function runCmd(array $args, string $command): array
    {
        // The importer clears compiled views through a nested Artisan call, which would replace Artisan::output(): capture this command's own.
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = Artisan::call($command, $args, $buffer);

        return [$code, $buffer->fetch()];
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'templates' => Template::withoutGlobalScopes()->withTrashed()->count(),
            'releases' => TemplateRelease::withoutGlobalScopes()->withTrashed()->count(),
            'events' => \DB::table('docuperfect_template_release_events')->count(),
            'log' => TemplateTransferLog::count(),
            'leases' => RentalLeaseTemplate::withoutGlobalScopes()->withTrashed()->count(),
        ];
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function test_export_writes_the_package_and_nothing_else(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        $before = $this->counts();

        [$code, $out] = $this->runCmd(['template_id' => $t->id, '--out' => $this->dir], 'templates:export');
        $this->assertSame(0, $code, $out);
        $files = glob($this->dir . '/*.cxpkg');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Wrote ', $out);
        $this->assertSame($before, $this->counts(), 'no audit row without --audit');
        $this->assertTrue(\App\Services\Docuperfect\TemplateTransfer\TemplatePackage::read($files[0])['data']['template']['name'] === $t->name);
    }

    public function test_export_refuses_unknown_templates_and_unusable_folders_and_audits_only_when_asked(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);

        [$code, $out] = $this->runCmd(['template_id' => 99999999, '--out' => $this->dir], 'templates:export');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no template', $out);

        [$code, $out] = $this->runCmd(['template_id' => $t->id, '--out' => $this->dir . '/does-not-exist'], 'templates:export');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('does not exist', $out);

        [$code, $out] = $this->runCmd(['template_id' => $t->id, '--out' => $this->dir, '--audit' => true], 'templates:export');
        $this->assertSame(1, $code, 'audit needs an actor');

        [$code] = $this->runCmd(['template_id' => $t->id, '--out' => $this->dir, '--audit' => true, '--actor' => $owner->id], 'templates:export');
        $this->assertSame(0, $code);
        $this->assertSame(1, TemplateTransferLog::where('direction', 'export')->count());
    }

    // ── import --dry-run ──────────────────────────────────────────────────────

    public function test_dry_run_says_what_would_happen_and_writes_nothing(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        RentalLeaseTemplate::withoutGlobalScopes()->create(['agency_id' => $w->id, 'name' => 'Mega Lease 2026', 'docuperfect_template_id' => $t->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true, 'field_map' => ['rent' => 'a', 'start_date' => 'b']]);
        $file = $this->packageFile($t, $owner);
        $this->megaShaped($client, $owner, ['name' => 'Mega Lease Agreement 2026']);   // the agency already has this name
        $before = $this->counts();
        $pagesBefore = count(glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')) ?: []);

        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => $client->id, '--dry-run' => true], 'templates:import');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN — nothing was written', $out);
        $this->assertStringContainsString('Mega Lease Agreement 2026', $out);
        $this->assertStringContainsString('v2', $out, 'the name clash is reported');
        $this->assertStringContainsString('lease agreement link: residential', $out);
        $this->assertStringContainsString('Cape Town Lettings', $out);
        $this->assertSame($before, $this->counts(), 'no template, release, log row or lease link');
        $this->assertSame($pagesBefore, count(glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')) ?: []), 'no page file');
    }

    public function test_dry_run_without_an_agency_still_previews_and_a_bad_file_or_agency_exits_1(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $file = $this->packageFile($this->megaShaped($w, $owner), $owner);

        [$code, $out] = $this->runCmd(['file' => $file, '--dry-run' => true], 'templates:import');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('not chosen', $out);

        [$code, $out] = $this->runCmd(['file' => $this->dir . '/missing.cxpkg', '--dry-run' => true], 'templates:import');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('does not exist', $out);

        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => 99999999, '--dry-run' => true], 'templates:import');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no agency with that number', $out);

        $junk = $this->dir . '/junk.cxpkg';
        file_put_contents($junk, 'this is not a zip');
        [$code, $out] = $this->runCmd(['file' => $junk, '--dry-run' => true], 'templates:import');
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('Exception', $out, 'a plain message, never a stack trace');
    }

    public function test_a_tampered_package_is_refused_in_a_dry_run_too(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $file = $this->packageFile($this->megaShaped($w, $owner), $owner);
        file_put_contents($file, substr((string) file_get_contents($file), 0, -40) . str_repeat('x', 40));

        [$code] = $this->runCmd(['file' => $file, '--dry-run' => true], 'templates:import');
        $this->assertSame(1, $code);
    }

    // ── import for real ───────────────────────────────────────────────────────

    public function test_a_real_import_needs_an_agency_and_the_owner(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $file = $this->packageFile($this->megaShaped($w, $owner), $owner);
        $before = $this->counts();

        [$code, $out] = $this->runCmd(['file' => $file], 'templates:import');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Choose the agency', $out);

        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => $client->id], 'templates:import');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--actor', $out);

        $admin = $this->agencyAdmin($client);
        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => $client->id, '--actor' => $admin->id], 'templates:import');
        $this->assertSame(1, $code, 'an agency admin cannot import');
        $this->assertSame($before, $this->counts());
    }

    public function test_a_real_import_always_lands_awaiting_approval_and_never_live(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner);
        $file = $this->packageFile($t, $owner);

        // No flag: an import is a release. There is no way to import straight to live.
        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => $client->id, '--actor' => $owner->id], 'templates:import');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('waiting for approval', $out);
        $this->assertStringNotContainsString('(live)', $out);
        $first = TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->first();
        $this->assertSame('awaiting_approval', $first->state);
        $firstCopy = Template::withoutGlobalScopes()->find($first->template_id);
        $this->trackBlade($firstCopy);
        $this->assertSame('awaiting_approval', $firstCopy->release_status);
        $this->assertSame(1, \DB::table('docuperfect_template_release_events')->where('release_id', $first->id)->where('event', 'released')->count(), 'the release log has its row');

        // The older --release flag is still accepted and changes nothing: a second package of the same document is the next version.
        [$code, $out] = $this->runCmd(['file' => $file, '--agency' => $client->id, '--actor' => $owner->id, '--release' => true], 'templates:import');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('waiting for approval', $out);
        $second = TemplateRelease::withoutGlobalScopes()->where('agency_id', $client->id)->orderByDesc('id')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, (int) $second->version);
        $copy = Template::withoutGlobalScopes()->find($second->template_id);
        $this->trackBlade($copy);
        $this->assertSame('awaiting_approval', $copy->release_status);
        $this->assertSame(0, Template::withoutGlobalScopes()->where('agency_id', $client->id)->whereNull('release_status')->count(), 'nothing is live at the agency');
    }
}
