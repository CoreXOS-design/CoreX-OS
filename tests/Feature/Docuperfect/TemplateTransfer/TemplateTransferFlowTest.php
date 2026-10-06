<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\TemplateTransfer;

use App\Models\Branch;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\DevSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackage;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Spec §2 (owner-only), §4 (preview -> agency -> confirm; never overwrite; one
 * transaction), §7 (audit incl. failures), §8 (settings drive behaviour).
 */
final class TemplateTransferFlowTest extends TestCase
{
    use RefreshDatabase;
    use TransferFixtures;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/template-transfer/staged/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function upload(User $user, string $bytes, string $name = 'pkg.cxpkg')
    {
        return $this->actingAs($user)->post(route('docuperfect.template-transfer.upload'), [
            'package' => UploadedFile::fake()->createWithContent($name, $bytes),
        ]);
    }

    private function tokenFrom($response): string
    {
        $this->assertTrue($response->isRedirect(), 'upload should redirect to the preview, got ' . $response->getStatusCode());
        $this->assertMatchesRegularExpression('#/template-transfer/preview/([A-Za-z0-9]{40})#', $response->headers->get('Location'));
        preg_match('#/preview/([A-Za-z0-9]{40})#', $response->headers->get('Location'), $m);

        return $m[1];
    }

    private function templatesNamed(int $agencyId, string $like): array
    {
        return Template::where('agency_id', $agencyId)->where('name', 'like', $like)->orderBy('id')->pluck('name')->all();
    }

    // ── permission ────────────────────────────────────────────────────────

    public function test_an_agency_admin_is_refused_everywhere_even_with_the_key_granted(): void
    {
        PermissionService::forceProductionPosture();
        $agency = $this->agency('Cape Town Lettings');
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        foreach (['templates.transfer', 'manage_templates', 'access_docuperfect'] as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $agency->id], []);
        }
        PermissionService::clearCache();
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $src = $this->webTemplate($agency, $admin);

        $restricted = 'This area is restricted to System Owners.';
        $this->actingAs($admin)->getJson(route('docuperfect.template-transfer.index'))->assertForbidden()->assertJsonFragment(['message' => $restricted]);
        $this->actingAs($admin)->getJson(route('docuperfect.templates.export', $src->id))->assertForbidden()->assertJsonFragment(['message' => $restricted]);
        $this->actingAs($admin)->postJson(route('docuperfect.templates.exportSelected'), ['ids' => [$src->id]])->assertForbidden();
        $this->actingAs($admin)->postJson(route('docuperfect.template-transfer.upload'))->assertForbidden();
        $this->actingAs($admin)->postJson(route('docuperfect.template-transfer.confirm', str_repeat('a', 40)), ['agency_id' => $agency->id])->assertForbidden();
        $this->actingAs($admin)->postJson(route('docuperfect.template-transfer.settings'), ['max_package_mb' => 99])->assertForbidden();
        $this->assertSame(0, TemplateTransferLog::count());

        // …and the list screen shows an admin no export controls.
        $html = $this->actingAs($admin)->get(route('docuperfect.templates.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Export selected', $html);
        $this->assertStringNotContainsString('Export package', $html);
        $this->assertStringNotContainsString('Template Packages', $html);
    }

    public function test_the_owner_sees_the_controls_and_can_download_a_valid_package(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $owner = $this->owner();
        $src = $this->webTemplate($hfc, $owner);

        $html = $this->actingAs($owner)->get(route('docuperfect.templates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Export selected', $html);
        $this->assertStringContainsString('Export package', $html);
        $this->assertStringContainsString('Template Packages', $html);

        $this->actingAs($owner)->get(route('docuperfect.template-transfer.index'))->assertOk()->assertSee('No transfers yet');

        $resp = $this->actingAs($owner)->get(route('docuperfect.templates.export', $src->id));
        $resp->assertOk();
        $this->assertStringContainsString('exclusive-authority-to-sell-', $resp->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.cxpkg"', $resp->headers->get('Content-Disposition'));
        $read = TemplatePackage::read($this->tmpFile($resp->getContent()));
        $this->assertSame('Exclusive Authority to Sell', $read['data']['template']['name']);

        $this->actingAs($owner)->get(route('docuperfect.template-transfer.index'))->assertOk()->assertSee('Exclusive Authority to Sell');
    }

    // ── upload -> preview -> confirm ──────────────────────────────────────

    public function test_upload_preview_choose_agency_confirm_creates_one_new_template(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner);

        $token = $this->tokenFrom($this->upload($owner, $pkg['bytes']));
        $this->assertSame(0, Template::where('agency_id', $cape->id)->count(), 'nothing is created before confirm');

        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', $token))
            ->assertOk()->assertSee('Exclusive Authority to Sell')->assertSee('Choose an agency')->assertSee('Nothing has been created yet');
        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', ['token' => $token, 'agency_id' => $cape->id]))
            ->assertOk()->assertSee('Cape Town Lettings')->assertDontSee('already has a template called');

        // No agency / no tick: refused, nothing created.
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['confirmed' => 1])->assertSessionHasErrors('agency_id');
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id])->assertSessionHasErrors('confirmed');
        $this->assertSame(0, Template::where('agency_id', $cape->id)->count());

        $resp = $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1]);
        $resp->assertRedirect(route('docuperfect.template-transfer.index'));
        $copy = Template::where('agency_id', $cape->id)->firstOrFail();
        $this->trackBlade($copy);
        $this->assertSame('Exclusive Authority to Sell', $copy->name);
        $this->assertSame(['Exclusive Authority to Sell'], Template::where('agency_id', $hfc->id)->pluck('name')->all(), 'the source is untouched');

        // Double confirm / replay: the staged file is gone, nothing more is created.
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1])
            ->assertRedirect(route('docuperfect.template-transfer.index'))->assertSessionHasErrors('package');
        $this->assertSame(1, Template::where('agency_id', $cape->id)->count());
    }

    public function test_cancel_removes_the_upload_and_creates_nothing(): void
    {
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($this->agency('Home Finders Coastal'), $owner), $owner);
        $token = $this->tokenFrom($this->upload($owner, $pkg['bytes']));
        $this->assertFileExists(storage_path("app/template-transfer/staged/{$token}.upload"));

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.cancel', $token))->assertRedirect(route('docuperfect.template-transfer.index'));
        $this->assertFileDoesNotExist(storage_path("app/template-transfer/staged/{$token}.upload"));
        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', $token))->assertRedirect(route('docuperfect.template-transfer.index'));
    }

    public function test_another_owners_staged_upload_cannot_be_used(): void
    {
        $owner = $this->owner();
        $cape = $this->agency('Cape Town Lettings');
        $pkg = $this->export($this->webTemplate($this->agency('Home Finders Coastal'), $owner), $owner);
        $token = $this->tokenFrom($this->upload($owner, $pkg['bytes']));

        $other = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin', 'name' => 'Other Owner']);
        $this->actingAs($other)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1])
            ->assertSessionHasErrors('package');
        $this->assertSame(0, Template::where('agency_id', $cape->id)->count());
    }

    public function test_a_bad_upload_is_refused_in_plain_language_and_logged(): void
    {
        $owner = $this->owner();

        $this->upload($owner, 'not a package at all')->assertSessionHasErrors('package');
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.upload'), [])->assertSessionHasErrors('package');
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.upload'), ['package' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertSessionHasErrors('package');

        $this->assertSame(1, TemplateTransferLog::where('outcome', 'rejected')->count());
        $this->assertSame([], glob(storage_path('app/template-transfer/staged/*')) ?: [], 'a refused upload leaves nothing staged');
    }

    // ── name clash (never overwrite) ──────────────────────────────────────

    public function test_name_clash_requires_a_choice_and_never_overwrites(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner);
        [$first] = $this->importInto($pkg['bytes'], $cape, $owner);
        $firstRow = (array) \DB::table('docuperfect_templates')->where('id', $first->id)->first();

        $token = $this->tokenFrom($this->upload($owner, $pkg['bytes']));
        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', ['token' => $token, 'agency_id' => $cape->id]))
            ->assertOk()->assertSee('already has a template called')->assertSee('will NOT be replaced');

        // No choice made -> refused, nothing created.
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1])->assertSessionHasErrors('package');
        $this->assertSame(1, Template::where('agency_id', $cape->id)->count());

        // A made-up choice is not a choice.
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1, 'choice' => [0 => 'replace']])->assertSessionHasErrors('package');
        $this->assertSame(1, Template::where('agency_id', $cape->id)->count());

        // New version.
        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1, 'choice' => [0 => 'new_version']])->assertRedirect(route('docuperfect.template-transfer.index'));
        $this->assertSame(['Exclusive Authority to Sell', 'Exclusive Authority to Sell v2'], $this->templatesNamed($cape->id, 'Exclusive%'));

        // Again: v3. New copy: dated.
        [$v3] = $this->importInto($pkg['bytes'], $cape, $owner, [0 => 'new_version']);
        $this->assertSame('Exclusive Authority to Sell v3', $v3->name);
        [$dated] = $this->importInto($pkg['bytes'], $cape, $owner, [0 => 'new_copy']);
        $this->assertSame('Exclusive Authority to Sell (imported ' . now()->format('d M Y') . ')', $dated->name);
        [$dated2] = $this->importInto($pkg['bytes'], $cape, $owner, [0 => 'new_copy']);
        $this->assertSame('Exclusive Authority to Sell (imported ' . now()->format('d M Y') . ') 2', $dated2->name);

        // The original row is byte-for-byte what it was.
        $this->assertSame($firstRow, (array) \DB::table('docuperfect_templates')->where('id', $first->id)->first());
        foreach (Template::where('agency_id', $cape->id)->get() as $t) {
            $this->trackBlade($t);
        }
    }

    public function test_an_archived_template_of_the_same_name_still_counts_as_a_clash(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner);
        [$first] = $this->importInto($pkg['bytes'], $cape, $owner);
        $first->update(['archived_at' => now()]);

        $this->expectException(TemplateTransferException::class);
        $this->expectExceptionMessage('already has a template called');
        $this->importInto($pkg['bytes'], $cape, $owner);
    }

    // ── transaction ───────────────────────────────────────────────────────

    public function test_a_failure_part_way_through_a_bundle_leaves_nothing_behind(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $a = $this->webTemplate($hfc, $owner, ['name' => 'Mandate Alpha']);
        $b = $this->webTemplate($hfc, $owner, ['name' => 'Mandate Beta']);

        // A package that ALSO needs a field definition and a group this system has never seen.
        $alpha = $this->reSealed($this->export($a, $owner)['bytes'], function ($d) {
            $d['named_fields']['nf1']['source_column'] = 'invented_column_for_rollback_test';
            $d['named_fields']['nf1']['name'] = 'Invented column';
            $d['field_groups']['fg1']['name'] = 'Invented group';

            return $d;
        });
        $bundle = TemplatePackage::buildBundle(['01-alpha.cxpkg' => $alpha, '02-beta.cxpkg' => $this->export($b, $owner)['bytes']]);

        $templatesBefore = Template::count();
        $fieldsBefore = NamedField::count();
        $groupsBefore = FieldGroup::withoutGlobalScopes()->count();
        $logsBefore = TemplateTransferLog::count();
        $bladesBefore = glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php'));

        // The SECOND template's page-file generation blows up, after the first was fully created.
        $this->app->bind(WebTemplateBladeEnsurer::class, fn () => new class extends WebTemplateBladeEnsurer {
            public static int $calls = 0;

            public function regenerate(Template $template): string
            {
                if (++self::$calls === 2) {
                    throw new \RuntimeException('simulated failure while writing the page file');
                }

                return parent::regenerate($template);
            }
        });

        $imp = app(TemplatePackageImporter::class);
        $loaded = $imp->load($this->tmpFile($bundle, 'zip'));
        $this->assertCount(2, $loaded);

        try {
            $imp->import($loaded, $cape, $owner, [], false);
            $this->fail('expected the import to fail');
        } catch (TemplateTransferException $e) {
            $this->assertStringContainsString('nothing was created', $e->getMessage());
            $this->assertStringNotContainsString('simulated', $e->getMessage(), 'no technical detail reaches the user');
        }

        $this->assertSame($templatesBefore, Template::count(), 'no template row remains (not even the first, fully built one)');
        $this->assertSame($fieldsBefore, NamedField::count(), 'no field definition was left behind');
        $this->assertSame($groupsBefore, FieldGroup::withoutGlobalScopes()->count(), 'no field group was left behind');
        $this->assertSame($bladesBefore, glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')), 'no page file was left behind');

        // …but the failure itself IS on record (written outside the rolled-back transaction).
        $this->assertSame($logsBefore + 2, TemplateTransferLog::count());
        $failed = TemplateTransferLog::where('outcome', 'failed')->get();
        $this->assertCount(2, $failed);
        $this->assertStringContainsString('simulated failure', (string) $failed->first()->failure_reason);
        $this->assertSame(0, TemplateTransferLog::where('outcome', 'success')->where('direction', 'import')->count());
    }

    public function test_a_bundle_of_two_round_trips_through_the_screens(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $a = $this->webTemplate($hfc, $owner, ['name' => 'Mandate Alpha']);
        $b = $this->webTemplate($hfc, $owner, ['name' => 'Mandate Beta']);

        $resp = $this->actingAs($owner)->post(route('docuperfect.templates.exportSelected'), ['ids' => [$a->id, $b->id, 999999]]);
        $resp->assertOk();
        $this->assertSame('application/zip', $resp->headers->get('Content-Type'));

        $token = $this->tokenFrom($this->upload($owner, $resp->getContent(), 'templates.zip'));
        $this->actingAs($owner)->get(route('docuperfect.template-transfer.preview', ['token' => $token, 'agency_id' => $cape->id]))
            ->assertOk()->assertSee('Mandate Alpha')->assertSee('Mandate Beta')->assertSee('these 2 templates');

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.confirm', $token), ['agency_id' => $cape->id, 'confirmed' => 1])->assertRedirect();
        $this->assertSame(['Mandate Alpha', 'Mandate Beta'], $this->templatesNamed($cape->id, 'Mandate%'));
        foreach (Template::where('agency_id', $cape->id)->get() as $t) {
            $this->trackBlade($t);
        }
    }

    public function test_export_selected_needs_a_selection_and_respects_the_limit(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('docuperfect.templates.exportSelected'), [])->assertRedirect(route('docuperfect.templates.index'))->assertSessionHasErrors('export');
        DevSetting::set('template_transfer.max_bundle_templates', '1');
        $this->actingAs($owner)->post(route('docuperfect.templates.exportSelected'), ['ids' => [1, 2]])->assertSessionHasErrors('export');
    }

    // ── document type / ECTA ──────────────────────────────────────────────

    public function test_a_missing_document_type_needs_a_tick_and_imports_without_one(): void
    {
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $good = $this->export($this->webTemplate($this->agency('Home Finders Coastal'), $owner), $owner)['bytes'];
        $pkg = $this->reSealed($good, function ($d) {
            $d['template']['document_type'] = ['slug' => 'cape_only_type', 'label' => 'Cape Only Type'];

            return $d;
        });

        $imp = app(TemplatePackageImporter::class);
        $preview = $imp->preview($imp->load($this->tmpFile($pkg)), $cape);
        $this->assertSame('missing', $preview[0]['document_type']['status']);
        $this->assertNotEmpty($preview[0]['needs_ack']);
        $this->assertEmpty($preview[0]['blocking']);

        try {
            $this->importInto($pkg, $cape, $owner);
            $this->fail('expected a confirmation to be required');
        } catch (TemplateTransferException $e) {
            $this->assertStringContainsString('needs your confirmation', $e->getMessage());
        }
        [$copy] = $this->importInto($pkg, $cape, $owner, [], true);
        $this->assertNull($copy->document_type_id);
    }

    public function test_a_missing_alienation_document_type_blocks_the_import(): void
    {
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        DocumentType::withTrashed()->where('slug', 'deed_of_sale')->forceDelete();
        $good = $this->export($this->webTemplate($this->agency('Home Finders Coastal'), $owner), $owner)['bytes'];
        $pkg = $this->reSealed($good, function ($d) {
            $d['template']['document_type'] = ['slug' => 'deed_of_sale', 'label' => 'Deed of Sale'];

            return $d;
        });

        $this->expectException(TemplateTransferException::class);
        $this->expectExceptionMessage('cannot be imported');
        $this->importInto($pkg, $cape, $owner, [], true);   // even with the tick: the law-blocked type cannot be dropped
    }

    public function test_an_alienation_document_is_imported_with_esigning_switched_off_and_the_preview_says_so(): void
    {
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $this->documentType('otp', 'Offer to Purchase');
        $good = $this->export($this->webTemplate($this->agency('Home Finders Coastal'), $owner), $owner)['bytes'];
        $pkg = $this->reSealed($good, function ($d) {
            $d['template']['name'] = 'Offer to Purchase — Sectional Title';
            $d['template']['is_esign'] = true;
            $d['template']['document_type'] = ['slug' => 'otp', 'label' => 'Offer to Purchase'];

            return $d;
        });

        $imp = app(TemplatePackageImporter::class);
        $logsBefore = \DB::table('legal_block_audit_log')->count();
        $preview = $imp->preview($imp->load($this->tmpFile($pkg)), $cape);
        $this->assertTrue($preview[0]['esign_switched_off']);
        $this->assertSame($logsBefore, \DB::table('legal_block_audit_log')->count(), 'previewing must not write the ECTA audit row');

        [$copy] = $this->importInto($pkg, $cape, $owner);
        $this->assertFalse((bool) $copy->is_esign, 'the model-level ECTA guard still has the last word');
    }

    // ── settings drive behaviour ──────────────────────────────────────────

    public function test_visibility_setting_decides_who_can_use_the_imported_template(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner)['bytes'];

        [$all] = $this->importInto($pkg, $cape, $owner);
        $this->assertTrue((bool) $all->is_global, 'default: every branch of the agency');
        $this->assertSame($cape->id, (int) $all->agency_id);

        $this->actingAs($owner)->post(route('docuperfect.template-transfer.settings'), [
            'max_package_mb' => 20, 'max_bundle_templates' => 25, 'default_visibility' => 'agency_admins_only',
            'version_suffix' => 'rev {n}', 'copy_suffix' => '(imported {date})', 'staged_upload_hours' => 24,
        ])->assertSessionHas('status');

        [$admins] = $this->importInto($pkg, $cape, $owner, [0 => 'new_version']);
        $this->assertFalse((bool) $admins->is_global);
        $this->assertSame('Exclusive Authority to Sell rev 2', $admins->name);
        $this->assertSame($cape->id, (int) $admins->agency_id, 'still never ownerless');
    }

    public function test_the_transfer_log_screen_searches_sorts_and_filters(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $cape = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $pkg = $this->export($this->webTemplate($hfc, $owner), $owner);
        $this->importInto($pkg['bytes'], $cape, $owner);
        $this->upload($owner, 'garbage');   // a refused one

        $base = route('docuperfect.template-transfer.index');
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Cape Town Lettings')->assertSee('Refused');
        $this->actingAs($owner)->get($base . '?search=Exclusive')->assertOk()->assertSee('Exclusive Authority to Sell');
        $this->actingAs($owner)->get($base . '?search=zzzz-nothing')->assertOk()->assertSee('No transfers match these filters');
        $this->actingAs($owner)->get($base . '?outcome=rejected')->assertOk()->assertSee('Refused')->assertDontSee('Cape Town Lettings');
        $this->actingAs($owner)->get($base . '?direction_filter=export')->assertOk()->assertDontSee('Cape Town Lettings');
        $this->actingAs($owner)->get($base . '?sort=template_name&direction=asc&from=2020-01-01&to=2099-01-01')->assertOk();
        $this->actingAs($owner)->get($base . '?from=not-a-date')->assertOk();
    }
}
