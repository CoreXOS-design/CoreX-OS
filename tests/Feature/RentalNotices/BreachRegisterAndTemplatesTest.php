<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\LeaseEvent;
use App\Models\RentalNoticeTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\NoticeDocxConverter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * rental-notices.md §3 + §6 - the agency's own uploaded notice templates (CRUD, archive, the Word upload) and the breach register (search, sort,
 * filter, scope, archive / restore).
 */
final class BreachRegisterAndTemplatesTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function breach(array $over = []): LeaseBreach
    {
        return LeaseBreach::withoutGlobalScopes()->create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'lease_id' => $this->lease->id, 'kind' => 'payment', 'breach_party' => 'tenant',
            'amount' => 1200, 'status' => 'issued', 'issued_on' => Carbon::today()->toDateString(), 'remedy_business_days' => 20,
            'remedy_deadline' => Carbon::today()->addDays(28)->toDateString(), 'issued_by_user_id' => $this->agent->id,
        ]);
    }

    // ── templates: upload ───────────────────────────────────────────────────

    /** A real (tiny) .docx - a zip with the parts Word needs - so the upload passes the file-type check. */
    private function docx(string $name = 'Breach notice.docx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Dear {{tenant_name}}</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function fakeConverter(string $html = '<p>Dear {{tenant_name}}, you owe {{amount}}.</p><script>alert(1)</script>'): void
    {
        $this->app->instance(NoticeDocxConverter::class, new class($html) extends NoticeDocxConverter {
            public function __construct(private string $fake) {}

            public function toTemplateHtml(string $path): string
            {
                return strip_tags($this->fake, '<p><br><strong>');
            }
        });
    }

    public function test_an_agency_uploads_its_own_word_document_as_a_template_and_the_original_is_kept(): void
    {
        $this->fakeConverter();

        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), [
            'name' => 'Our payment breach', 'notice_type' => 'breach_payment', 'is_active' => '1', 'document' => $this->docx(),
        ])->assertRedirect(route('corex.rental-notice-templates.index'))->assertSessionHasNoErrors();

        $t = RentalNoticeTemplate::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->agency->id, $t->agency_id);
        $this->assertSame('breach_payment', $t->notice_type);
        $this->assertStringContainsString('Dear {{tenant_name}}, you owe {{amount}}.', $t->body_html);
        $this->assertStringNotContainsString('script', $t->body_html, 'only plain document markup is kept');
        $this->assertSame('Breach notice.docx', $t->source_file_name);
        Storage::disk('local')->assertExists($t->source_file_path);
    }

    public function test_the_template_needs_a_document_or_typed_text_and_a_bad_document_says_so(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), ['name' => 'Empty', 'notice_type' => 'notice_to_vacate'])->assertSessionHasErrors('body_html');

        $this->app->instance(NoticeDocxConverter::class, new class extends NoticeDocxConverter {
            public function toTemplateHtml(string $path): string
            {
                throw new \RuntimeException('Mammoth returned empty HTML.');
            }
        });
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), ['name' => 'Bad', 'notice_type' => 'notice_to_vacate', 'document' => $this->docx()])
            ->assertSessionHasErrors('document');
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count());

        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), ['name' => 'A pdf', 'notice_type' => 'notice_to_vacate', 'document' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('document');
    }

    public function test_a_template_can_be_typed_edited_archived_and_restored_and_the_three_types_are_offered(): void
    {
        $form = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.create', ['notice_type' => 'breach_other']))->assertOk()->getContent();
        foreach (['Breach notice (payment)', 'Breach notice (other)', 'Notice to vacate'] as $label) {
            $this->assertStringContainsString($label, $form);
        }
        $this->assertStringContainsString('type="file" name="document"', $form);
        $this->assertMatchesRegularExpression('/<option value="breach_other"\s+selected/', $form, 'the type from the link is preselected');

        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), ['name' => 'Typed', 'notice_type' => 'notice_to_vacate', 'body_html' => '<p>Vacate {{property_address}}</p>'])->assertSessionHasNoErrors();
        $t = RentalNoticeTemplate::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->agent)->put(route('corex.rental-notice-templates.update', $t), ['name' => 'Typed v2', 'notice_type' => 'notice_to_vacate', 'body_html' => '<p>Please vacate {{property_address}}</p>', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('Typed v2', $t->fresh()->name);

        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.archive', $t))->assertRedirect();
        $this->assertSoftDeleted('rental_notice_templates', ['id' => $t->id]);
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.restore', $t->id))->assertRedirect();
        $this->assertNull($t->fresh()->deleted_at);
    }

    public function test_one_agencys_templates_are_not_another_agencys(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $theirs = RentalNoticeTemplate::create(['agency_id' => $other->id, 'name' => 'Cape breach', 'notice_type' => 'breach_payment', 'body_html' => '<p>x</p>', 'is_active' => true]);

        $list = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Cape breach', $list);
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.edit', $theirs))->assertNotFound();
    }

    // ── register ────────────────────────────────────────────────────────────

    public function test_the_register_searches_sorts_filters_and_paginates(): void
    {
        $a = $this->breach(['reason' => null, 'amount' => 900, 'issued_on' => Carbon::today()->subDays(10)->toDateString(), 'remedy_deadline' => Carbon::today()->addDays(5)->toDateString()]);
        $b = $this->breach(['kind' => 'other', 'amount' => null, 'clause' => '9.2 pool', 'reason' => 'Pool is green', 'status' => 'remedied', 'issued_on' => Carbon::today()->subDays(2)->toDateString()]);
        $c = $this->breach(['kind' => 'other', 'breach_party' => 'landlord', 'amount' => null, 'clause' => '12 roof', 'reason' => 'Roof leaks', 'status' => 'escalated']);

        $row = fn (LeaseBreach $x) => 'data-breach-row="' . $x->id . '"';
        $all = $this->actingAs($this->agent)->get(route('corex.breaches.index'))->assertOk()->getContent();
        foreach ([$a, $b, $c] as $x) {
            $this->assertStringContainsString($row($x), $all);
        }
        // search: reason, clause, tenant name, landlord name, property address
        $this->assertStringContainsString($row($b), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'green']))->getContent());
        $this->assertStringNotContainsString($row($a), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'green']))->getContent());
        $this->assertStringContainsString($row($c), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'roof']))->getContent());
        $this->assertStringContainsString($row($a), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'Tina']))->getContent());
        $this->assertStringContainsString($row($a), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'Lenny']))->getContent());
        $this->assertStringContainsString($row($a), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'Vlei Court']))->getContent());
        // filters
        $this->assertStringNotContainsString($row($a), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['status' => 'remedied']))->getContent());
        $this->assertStringContainsString($row($b), $this->actingAs($this->agent)->get(route('corex.breaches.index', ['status' => 'remedied']))->getContent());
        $kind = $this->actingAs($this->agent)->get(route('corex.breaches.index', ['kind' => 'payment']))->getContent();
        $this->assertStringContainsString($row($a), $kind);
        $this->assertStringNotContainsString($row($b), $kind);
        $party = $this->actingAs($this->agent)->get(route('corex.breaches.index', ['party' => 'landlord']))->getContent();
        $this->assertStringContainsString($row($c), $party);
        $this->assertStringNotContainsString($row($a), $party);
        $dated = $this->actingAs($this->agent)->get(route('corex.breaches.index', ['date_from' => Carbon::today()->subDays(3)->toDateString()]))->getContent();
        $this->assertStringNotContainsString($row($a), $dated);
        // sort: every column answers, default newest first
        foreach (['issued_on', 'remedy_deadline', 'amount', 'status', 'property'] as $sort) {
            $this->actingAs($this->agent)->get(route('corex.breaches.index', ['sort' => $sort, 'dir' => 'asc']))->assertOk();
        }
        $sorted = $this->actingAs($this->agent)->get(route('corex.breaches.index', ['sort' => 'issued_on', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($sorted, $row($b)), strpos($sorted, $row($a)), 'oldest first when asked');
    }

    public function test_an_empty_register_and_an_empty_filter_say_what_to_do(): void
    {
        $this->actingAs($this->agent)->get(route('corex.breaches.index'))->assertOk()->assertSee('data-qa="breach-empty"', false)->assertSee('No breach notices have been issued yet');
        $this->breach();
        $this->actingAs($this->agent)->get(route('corex.breaches.index', ['q' => 'nothing like this']))->assertOk()->assertSee('No breach notices match these filters');
    }

    public function test_scope_follows_the_lease_and_another_agency_is_a_404(): void
    {
        $breach = $this->breach();
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'C-' . $other->id, 'is_active' => true]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch2->id, 'role' => 'admin']);

        $this->actingAs($stranger)->get(route('corex.breaches.show', $breach->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('corex.breaches.remedy', $breach->id), [])->assertNotFound();
        $this->assertStringNotContainsString('data-breach-row="' . $breach->id . '"', $this->actingAs($stranger)->get(route('corex.breaches.index'))->getContent());

        // another branch, branch-scoped: 403 by id, absent from the list
        $branch3 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Third', 'code' => 'T-' . $this->agency->id, 'is_active' => true]);
        $branchAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch3->id, 'role' => 'branch_three']);
        foreach (['rental_breaches.view', 'rental_breaches.manage', 'leases.view'] as $key) {
            RolePermission::create(['role' => 'branch_three', 'permission_key' => $key, 'scope' => 'branch']);
        }
        PermissionService::clearCache();
        $this->assertContains($this->actingAs($branchAgent)->get(route('corex.breaches.show', $breach->id))->getStatusCode(), [403, 404], 'another branch cannot open it by id');
        $this->assertStringNotContainsString('data-breach-row="' . $breach->id . '"', $this->actingAs($branchAgent)->get(route('corex.breaches.index'))->getContent());
    }

    public function test_archive_and_restore_are_soft_and_logged_and_the_archived_filter_finds_it(): void
    {
        $breach = $this->breach();

        $this->actingAs($this->agent)->post(route('corex.breaches.archive', $breach->id))->assertRedirect(route('corex.breaches.index'));
        $this->assertSoftDeleted('lease_breaches', ['id' => $breach->id]);
        $this->assertStringNotContainsString('data-breach-row="' . $breach->id . '"', $this->actingAs($this->agent)->get(route('corex.breaches.index'))->getContent());
        $this->assertStringContainsString('data-breach-row="' . $breach->id . '"', $this->actingAs($this->agent)->get(route('corex.breaches.index', ['archived' => 1]))->getContent());

        $this->actingAs($this->agent)->post(route('corex.breaches.restore', $breach->id))->assertRedirect();
        $this->assertNull($breach->fresh()->deleted_at);
        $this->assertSame(2, LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_BREACH_EDITED)->count());
    }

    public function test_the_nav_link_and_the_navigation_entry_exist(): void
    {
        $this->actingAs($this->agent)->get(route('corex.breaches.index'))->assertOk()->assertSee('Breach register');
        $html = $this->actingAs($this->agent)->get(route('corex.leases.index'))->getContent();
        $this->assertStringContainsString(route('corex.breaches.index'), $html, 'Breach Register is in the Rentals menu');
    }

    public function test_the_notice_document_downloads_for_people_who_can_see_the_lease(): void
    {
        $tpl = RentalNoticeTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Pay breach', 'notice_type' => 'breach_payment', 'is_active' => true, 'body_html' => '<p>{{tenant_name}} owes {{amount}}</p>']);
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '1000']])->assertOk();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->agent)->get(route('corex.breaches.document', [$breach->id, 'breach']))->assertOk()->assertDownload();
        $this->actingAs($this->agent)->get(route('corex.breaches.document', [$breach->id, 'vacate']))->assertNotFound();
    }

    /** Audit 2026-10-18 (A-L7): an arbitrary ?sort= column used to reach orderBy() (a 500 for a bad name, a probe for hidden columns). */
    public function test_the_sent_notices_list_ignores_a_sort_column_that_is_not_on_the_whitelist(): void
    {
        // a row must exist, otherwise paginate() never runs the ordered select and a bad column goes unnoticed
        \App\Models\RentalNotice::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_type' => 'breach', 'sent_at' => now(), 'sent_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['sort' => 'no_such_column']))->assertOk();
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['sort' => 'sent_by_user_id; drop table x']))->assertOk();
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['sort' => 'notice_type']))->assertOk();
    }
}
