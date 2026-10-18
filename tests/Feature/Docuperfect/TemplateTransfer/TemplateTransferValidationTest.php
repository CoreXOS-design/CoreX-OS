<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\TemplateTransfer;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\DevSetting;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackage;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec §6, §10: a package is validated before anything is written — format version,
 * checksum, required parts, shape, and a safety scan that does NOT trust the checksum.
 */
final class TemplateTransferValidationTest extends TestCase
{
    use RefreshDatabase;
    use TransferFixtures;

    private function load(string $bytes): array
    {
        return app(TemplatePackageImporter::class)->load($this->tmpFile($bytes));
    }

    private function assertRefused(string $bytes, string $messagePart): void
    {
        $before = Template::count();
        try {
            $this->load($bytes);
            $this->fail('expected the package to be refused');
        } catch (TemplateTransferException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage() . ' ' . implode(' ', $e->details));
        }
        $this->assertSame($before, Template::count(), 'a refused package must create nothing');
    }

    private function goodPackage(): string
    {
        $hfc = $this->agency('Home Finders Coastal');
        $owner = $this->owner();

        return $this->export($this->webTemplate($hfc, $owner), $owner)['bytes'];
    }

    public function test_a_valid_package_loads(): void
    {
        $loaded = $this->load($this->goodPackage());
        $this->assertCount(1, $loaded);
        $this->assertSame('Exclusive Authority to Sell', $loaded[0]['data']['template']['name']);
    }

    public function test_tampered_content_fails_the_checksum(): void
    {
        $tampered = $this->rewriteZip($this->goodPackage(), function (array $e) {
            $e['template.json'] = str_replace('Exclusive Authority to Sell', 'Exclusive Authority to Steal', $e['template.json']);

            return $e;
        });
        $this->assertRefused($tampered, 'integrity check');
    }

    public function test_a_tampered_checksum_value_is_refused(): void
    {
        $tampered = $this->rewriteZip($this->goodPackage(), function (array $e) {
            $m = json_decode($e['manifest.json'], true);
            $m['package_checksum'] = str_repeat('a', 64);
            $e['manifest.json'] = json_encode($m);

            return $e;
        });
        $this->assertRefused($tampered, 'integrity check');
    }

    public function test_a_newer_format_version_is_refused_with_a_plain_message(): void
    {
        $newer = $this->rewriteZip($this->goodPackage(), function (array $e) {
            $m = json_decode($e['manifest.json'], true);
            $m['format_version'] = 2;
            $e['manifest.json'] = json_encode($m);

            return $e;
        });
        $this->assertRefused($newer, 'newer version of CoreX');
    }

    public function test_an_unsupported_or_missing_format_version_is_refused(): void
    {
        foreach ([0, 'one', null] as $bad) {
            $pkg = $this->rewriteZip($this->goodPackage(), function (array $e) use ($bad) {
                $m = json_decode($e['manifest.json'], true);
                $m['format_version'] = $bad;
                $e['manifest.json'] = json_encode($m);

                return $e;
            });
            $this->assertRefused($pkg, 'format version');
        }
    }

    public function test_missing_required_parts_are_refused(): void
    {
        $good = $this->goodPackage();
        $this->assertRefused($this->rewriteZip($good, fn ($e) => array_diff_key($e, ['template.json' => 1])), 'incomplete');
        $this->assertRefused($this->rewriteZip($good, fn ($e) => array_diff_key($e, ['manifest.json' => 1])), 'incomplete');
    }

    public function test_foreign_entries_and_path_traversal_are_refused(): void
    {
        $good = $this->goodPackage();
        $this->assertRefused($this->rewriteZip($good, fn ($e) => $e + ['../../public/evil.php' => '<?php echo 1;']), 'do not belong');
        $this->assertRefused($this->rewriteZip($good, fn ($e) => $e + ['files/shell.php' => '<?php echo 1;']), 'do not belong');
    }

    public function test_not_a_zip_empty_and_oversize_files_are_refused(): void
    {
        $this->assertRefused('this is not a zip file at all', 'cannot be opened');
        $this->assertRefused('', 'empty');

        DevSetting::set('template_transfer.max_package_mb', '1');
        $this->assertRefused(str_repeat('A', 1024 * 1024 + 10), 'larger than the allowed 1 MB');
    }

    public function test_unsafe_content_is_refused_even_when_the_checksum_is_valid(): void
    {
        $good = $this->goodPackage();
        $attacks = [
            'PHP code'          => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '<?php system($_GET["c"]); ?>'),
            'template expression' => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '{{ system("id") }}'),
            'unescaped output'  => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '{!! file_get_contents("/etc/passwd") !!}'),
            'php directive'     => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= "\n@php system('id') @endphp"),
            'include directive' => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['cds_json']['title'] = "@include('admin.secrets')"),
            'script'            => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '<script>alert(1)</script>'),
            'javascript link'   => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '<a href="javascript:alert(1)">x</a>'),
            'event handler'     => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '<img src="x" onerror="alert(1)">'),
            'role token'        => fn ($d) => $this->mut($d, fn (&$x) => $x['template']['signing_parties'][] = '"]) @php evil() @endphp ["'),
        ];
        foreach ($attacks as $label => $edit) {
            $this->assertRefused($this->reSealed($good, $edit), 'cannot be imported');
        }
        $this->assertSame(0, TemplateTransferLog::where('direction', 'import')->where('outcome', 'success')->count());
    }

    public function test_ordinary_text_that_looks_a_bit_like_code_is_not_refused(): void
    {
        // Emails, "@" in prose and braces in normal text are fine — only execution syntax is refused.
        $ok = $this->reSealed($this->goodPackage(), fn ($d) => $this->mut($d, fn (&$x) => $x['template']['editor_state']['tagged_html'] .= '<p>Send to info@homefinders.co.za — price {R 1 250 000} (see @ clause 4)</p>'));
        $this->assertCount(1, $this->load($ok));
    }

    public function test_malformed_template_parts_are_refused_in_plain_language(): void
    {
        $good = $this->goodPackage();
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template']['name'] = '   ')), 'no valid name');
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template']['render_type'] = 'flash')), 'pdf');
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template']['category'] = 'commercial')), 'category');
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template']['field_mappings'] = 'oops')), 'damaged');
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template'] = ['editor_state' => ['tagged_html' => '', 'tags' => [], 'mappings' => []], 'cds_json' => ['sections' => []]] + $x['template'])), 'no wording');
        $this->assertRefused($this->reSealed($good, fn ($d) => $this->mut($d, fn (&$x) => $x['template']['field_mappings']['tag-x'] = ['namedFieldId' => '@nf999'])), 'not in the package');
    }

    public function test_a_pdf_package_with_a_missing_page_image_is_refused(): void
    {
        Storage::fake();
        $owner = $this->owner();
        $pdf = $this->pdfTemplate($this->agency('Home Finders Coastal'), $owner, 2);
        $bytes = $this->export($pdf, $owner)['bytes'];

        $missing = $this->reSealed($bytes, fn ($d) => $d);   // sanity: intact one loads
        $this->assertCount(1, $this->load($missing));

        $dropped = $this->rewriteZip($bytes, function (array $e) {
            unset($e['files/page-1.png']);
            $m = json_decode($e['manifest.json'], true);
            unset($m['files']['files/page-1.png']);
            $lines = '';
            ksort($m['files']);
            foreach ($m['files'] as $n => $h) {
                $lines .= $n . ':' . $h . "\n";
            }
            $m['package_checksum'] = hash('sha256', $lines);
            $e['manifest.json'] = json_encode($m);

            return $e;
        });
        $this->assertRefused($dropped, 'image for page 2 is missing');
    }

    public function test_exporting_a_web_template_whose_wording_lives_only_in_a_page_file_is_refused(): void
    {
        $owner = $this->owner();
        $t = Template::create(['name' => 'Legacy page-file only', 'render_type' => 'web', 'template_type' => 'cds', 'agency_id' => $this->agency('Home Finders Coastal')->id, 'owner_id' => $owner->id, 'blade_view' => 'docuperfect.web-templates.imported.legacy']);
        $this->expectException(TemplateTransferException::class);
        $this->expectExceptionMessage('builder and save it once');
        $this->export($t, $owner);
    }

    public function test_settings_validate_and_defaults_apply(): void
    {
        $this->assertSame(20, TemplateTransferSettings::int('max_package_mb'));
        $this->assertSame('all_branches', TemplateTransferSettings::visibility());
        $this->assertSame('v{n}', TemplateTransferSettings::pattern('version_suffix'));

        try {
            TemplateTransferSettings::save(['max_package_mb' => '0', 'max_bundle_templates' => 'many', 'default_visibility' => 'everyone', 'version_suffix' => '<b>{n}', 'copy_suffix' => '', 'staged_upload_hours' => '9999']);
            $this->fail('expected invalid settings to be refused');
        } catch (TemplateTransferException $e) {
            $this->assertCount(6, $e->details);
        }
        $this->assertSame(20, TemplateTransferSettings::int('max_package_mb'), 'a refused save changes nothing');

        TemplateTransferSettings::save(['max_package_mb' => '5', 'max_bundle_templates' => '3', 'default_visibility' => 'agency_admins_only', 'version_suffix' => 'rev {n}', 'copy_suffix' => 'copy {date}', 'staged_upload_hours' => '2']);
        $this->assertSame(5, TemplateTransferSettings::int('max_package_mb'));
        $this->assertSame('agency_admins_only', TemplateTransferSettings::visibility());
    }
}
