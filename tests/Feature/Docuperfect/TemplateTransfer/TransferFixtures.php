<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\TemplateTransfer;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\Role;
use App\Models\User;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageExporter;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Shared fixtures for the template-transfer tests. The web template is built from the
 * REAL captured "Exclusive Authority to Sell" (database/seeders/data) — real wording,
 * real field bindings, a real field group — not a toy "Test" template.
 */
trait TransferFixtures
{
    /** @var int[] template ids whose generated page file must be removed afterwards */
    protected array $bladeIds = [];

    /**
     * Template ids in these tests start above this. Tracked page snapshots exist for low ids
     * (cds/template-111.blade.php ...); a fresh test row numbered 112 would otherwise be written
     * over — and then deleted by tearDown — a committed file. (It happened once; see the spec.)
     */
    private const SAFE_ID_FLOOR = 900000;

    protected function setUp(): void
    {
        parent::setUp();
        // An explicit high id moves the table's auto-increment counter past it for good (the row
        // itself is rolled back with the test transaction, the counter is not).
        \DB::table('docuperfect_templates')->insert(['id' => self::SAFE_ID_FLOOR, 'name' => 'id anchor', 'template_type' => 'sales', 'render_type' => 'pdf']);
    }

    protected function tearDown(): void
    {
        // Every page file a test run generated (ids at or above the floor — never a committed snapshot).
        foreach (glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')) ?: [] as $file) {
            if (preg_match('/template-(\d+)\.blade\.php$/', $file, $m) && (int) $m[1] >= self::SAFE_ID_FLOOR) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    protected function trackBlade(Template $t): Template
    {
        $this->bladeIds[] = (int) $t->id;

        return $t;
    }

    protected function agency(string $name): Agency
    {
        return Model::withoutEvents(fn () => Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]));
    }

    protected function owner(): User
    {
        $role = Role::query()->where('name', 'super_admin')->first() ?? Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin', 'name' => 'Johan Owner']);
    }

    protected function documentType(string $slug = 'mandate', string $label = 'Mandate'): DocumentType
    {
        return DocumentType::firstOrCreate(['slug' => $slug], ['label' => $label, 'grouping' => 'shared', 'is_active' => true, 'sort_order' => 1]);
    }

    /** The real captured web template, created as agency $agency's own, with a real field group. */
    protected function webTemplate(Agency $agency, User $owner, array $overrides = []): Template
    {
        $d = json_decode((string) file_get_contents(base_path('database/seeders/data/exclusive-authority-to-sell.json')), true);
        // The capture stores some columns as JSON *strings* (the seeders insert them raw); a real row holds JSON.
        foreach (['cds_json', 'fields_json', 'signing_parties', 'editor_state', 'sections', 'field_mappings'] as $col) {
            if (is_string($d[$col] ?? null) && $d[$col] !== '') {
                $d[$col] = json_decode($d[$col], true);
            }
        }

        $nfIds = [];
        foreach ($d['named_field_refs'] as $old => $ref) {
            $nfIds[(int) $old] = NamedField::firstOrCreate(
                ['source_type' => $ref['source_type'], 'source_column' => $ref['source_column'], 'source_contact_type' => $ref['source_contact_type'] ?? null],
                ['name' => $ref['name'], 'field_type' => $ref['field_type'] ?? 'text', 'sort_order' => 900]
            )->id;
        }
        $fgIds = [];
        $remap = function ($node) use (&$remap, &$nfIds, &$fgIds, $agency, $owner) {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $k => $v) {
                if ($k === 'namedFieldId' && $v !== null && isset($nfIds[(int) $v])) {
                    $out[$k] = $nfIds[(int) $v];
                } elseif ($k === 'fieldGroupId' && $v !== null) {
                    $fgIds[(int) $v] ??= FieldGroup::withoutAgencyStamping(fn () => FieldGroup::create([
                        'agency_id' => $agency->id, 'created_by' => $owner->id, 'name' => 'Seller details',
                        'fields' => [['named_field_id' => array_values($nfIds)[0], 'label_override' => null], ['named_field_id' => array_values($nfIds)[1], 'label_override' => 'Street']],
                        'layout' => 'vertical', 'sort_order' => 1, 'is_global' => false,
                    ]))->id;
                    $out[$k] = $fgIds[(int) $v];
                } elseif ($k === 'typeKey' && is_string($v) && preg_match('/^fg:(\d+)$/', $v, $m)) {
                    $fgIds[(int) $m[1]] ??= FieldGroup::withoutAgencyStamping(fn () => FieldGroup::create([
                        'agency_id' => $agency->id, 'created_by' => $owner->id, 'name' => 'Seller details',
                        'fields' => [['named_field_id' => array_values($nfIds)[0], 'label_override' => null]],
                        'layout' => 'vertical', 'sort_order' => 1, 'is_global' => false,
                    ]))->id;
                    $out[$k] = 'fg:' . $fgIds[(int) $m[1]];
                } else {
                    $out[$k] = $remap($v);
                }
            }

            return $out;
        };

        $cols = $d['columns'];
        unset($cols['blade_view'], $cols['document_type_id']);

        return Template::create(array_merge($cols, [
            'document_type_id' => $this->documentType()->id,
            'cds_json'         => $d['cds_json'],
            'fields_json'      => $d['fields_json'],
            'signing_parties'  => $d['signing_parties'],
            'editor_state'     => $remap($d['editor_state']),
            'sections'         => is_array($d['sections'] ?? null) ? $d['sections'] : null,
            'field_mappings'   => $remap($d['field_mappings']),
            'agency_id'        => $agency->id,
            'owner_id'         => $owner->id,
            'is_global'        => true,
        ], $overrides));
    }

    /** A PDF template with real-looking page images and two signature zones. */
    protected function pdfTemplate(Agency $agency, User $owner, int $pages = 2): Template
    {
        $t = Template::create([
            'name' => 'Lease Addendum (scanned)', 'template_type' => 'rental', 'render_type' => 'pdf', 'category' => 'rentals',
            'page_count' => $pages, 'is_esign' => true, 'agency_id' => $agency->id, 'owner_id' => $owner->id, 'is_global' => true,
            'fields_json' => [['key' => 'tenant_name', 'label' => 'Tenant name', 'type' => 'text', 'page' => 0, 'x' => 12.5, 'y' => 30.25]],
            'signing_parties' => ['owner_party', 'acquiring_party'],
        ]);
        for ($i = 0; $i < $pages; $i++) {
            \Storage::put("docuperfect/templates/{$t->id}/page-{$i}.png", "\x89PNG\r\n\x1a\n" . "page-{$i}-" . str_repeat('x', 200));
        }
        foreach ([0, 1] as $n) {
            TemplateSignatureZone::create(['template_id' => $t->id, 'page_index' => min($n, $pages - 1), 'x_position' => 10 + $n, 'y_position' => 80, 'width' => 25, 'height' => 6, 'type' => 'signature', 'assigned_parties' => ['owner_party'], 'label' => 'Landlord', 'required' => true, 'sort_order' => $n]);
        }

        return $t->fresh();   // as a real row reads back, with the column defaults filled in
    }

    /** @return array{bytes:string, checksum:string, filename:string, warnings:array} */
    protected function export(Template $t, User $actor): array
    {
        return app(TemplatePackageExporter::class)->export($t, $actor);
    }

    /** Write bytes to a temp file and return its path. */
    protected function tmpFile(string $bytes, string $ext = 'cxpkg'): string
    {
        $path = sys_get_temp_dir() . '/' . Str::random(12) . '.' . $ext;
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Import $bytes into $agency as $actor; returns the created templates (and tracks their page files). */
    protected function importInto(string $bytes, Agency $agency, User $actor, array $choices = [], bool $allowNoType = false): array
    {
        $imp = app(TemplatePackageImporter::class);
        $loaded = $imp->load($this->tmpFile($bytes));
        $created = $imp->import($loaded, $agency, $actor, $choices, $allowNoType);
        foreach ($created as $t) {
            $this->trackBlade($t);
        }

        return $created;
    }

    /** Rewrite a package zip: $mutate receives [name => bytes] and returns the new set. */
    protected function rewriteZip(string $bytes, callable $mutate): string
    {
        $in = $this->tmpFile($bytes);
        $zip = new ZipArchive();
        $zip->open($in);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($in);

        $entries = $mutate($entries);
        $out = $this->tmpFile('', 'zip');
        $zip = new ZipArchive();
        $zip->open($out, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $result = (string) file_get_contents($out);
        @unlink($out);

        return $result;
    }

    protected function sentDocument(Template $t, User $owner, Agency $agency): Document
    {
        return Document::create([
            'name' => 'Mandate — 14 Marine Drive, Margate', 'template_id' => $t->id, 'owner_id' => $owner->id, 'agency_id' => $agency->id,
            'fields_json' => [['key' => 'seller', 'value' => 'M. Naidoo']],
            'web_template_data' => ['merged_html' => '<p>FROZEN wording for 14 Marine Drive</p>', 'canonical_html' => '<p>FROZEN</p>'],
        ]);
    }

    /** Apply a by-reference edit and return the edited copy (tap() would discard it). */
    protected function mut(array $d, callable $edit): array
    {
        $edit($d);

        return $d;
    }

    /** Rebuild every checksum so only the CONTENT is wrong — what an attacker with the format in hand would do. */
    protected function reSealed(string $bytes, callable $editTemplateJson): string
    {
        return $this->rewriteZip($bytes, function (array $e) use ($editTemplateJson) {
            $data = json_decode($e['template.json'], true);
            $data = $editTemplateJson($data);
            $e['template.json'] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hashes = [];
            foreach ($e as $name => $b) {
                if ($name !== 'manifest.json') {
                    $hashes[$name] = hash('sha256', $b);
                }
            }
            ksort($hashes);
            $lines = '';
            foreach ($hashes as $n => $h) {
                $lines .= $n . ':' . $h . "\n";
            }
            $m = json_decode($e['manifest.json'], true);
            $m['files'] = $hashes;
            $m['package_checksum'] = hash('sha256', $lines);
            $e['manifest.json'] = json_encode($m);

            return $e;
        });
    }

}
