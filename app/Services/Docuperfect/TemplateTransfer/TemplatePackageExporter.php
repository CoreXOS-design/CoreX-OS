<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use App\Events\Docuperfect\TemplatePackageExported;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns one e-sign template into a self-contained package. READ-ONLY with respect
 * to the template (and everything hanging off it, sent documents included): the
 * only write is the audit row. Spec: .ai/specs/esign-template-transfer.md §3.
 */
final class TemplatePackageExporter
{
    /** Content columns carried verbatim (after id -> token rewriting). */
    private const CONTENT_COLUMNS = [
        'signing_parties', 'cds_json', 'editor_state', 'fields_json',
        'field_mappings', 'wizard_config', 'sections', 'insertable_blocks',
    ];

    /**
     * @return array{filename:string, bytes:string, checksum:string, warnings:string[]}
     * @throws TemplateTransferException when the template cannot be exported faithfully
     */
    public function export(Template $template, User $actor): array
    {
        $template->loadMissing('signatureZones');

        $this->assertExportable($template);

        $warnings = [];
        $removed = [];

        // id -> token caches (null = the row the template points at no longer exists)
        $nfTokens = [];
        $nfDefs = [];
        $fgTokens = [];
        $fgDefs = [];

        $nf = function (int $id) use (&$nfTokens, &$nfDefs, &$warnings): ?string {
            if (array_key_exists($id, $nfTokens)) {
                return $nfTokens[$id];
            }
            $row = NamedField::withTrashed()->find($id);
            if (! $row) {
                $warnings[] = 'A field definition this template pointed at no longer exists in this system; that field is exported without a definition.';

                return $nfTokens[$id] = null;
            }
            $token = 'nf' . (count($nfDefs) + 1);
            $nfDefs[$token] = [
                'name'                => $row->name,
                'field_type'          => $row->field_type,
                'default_options'     => $row->default_options,
                'sort_order'          => $row->sort_order,
                'source_type'         => $row->source_type,
                'source_column'       => $row->source_column,
                'source_contact_type' => $row->source_contact_type,
            ];

            return $nfTokens[$id] = $token;
        };

        $fg = function (int $id) use (&$fgTokens, &$fgDefs, &$warnings, &$removed, $nf, &$fg): ?string {
            if (array_key_exists($id, $fgTokens)) {
                return $fgTokens[$id];
            }
            $row = FieldGroup::withoutGlobalScopes()->withTrashed()->find($id);
            if (! $row) {
                $warnings[] = 'A field group this template pointed at no longer exists in this system; that field is exported without a group.';

                return $fgTokens[$id] = null;
            }
            $token = 'fg' . (count($fgDefs) + 1);
            $fgTokens[$id] = $token;
            $fgDefs[$token] = [
                'name'        => $row->name,
                'description' => $row->description,
                'layout'      => $row->layout,
                'sort_order'  => $row->sort_order,
                'members'     => TemplateReferenceMap::toTokens($row->fields ?? [], 'field_group', $nf, $fg, $removed),
            ];

            return $token;
        };

        $body = [
            'name'                   => (string) $template->name,
            'render_type'            => (string) $template->render_type,
            'template_type'          => (string) $template->template_type,
            'category'               => $template->category,
            'page_count'             => (int) $template->page_count,
            'is_esign'               => (bool) $template->is_esign,
            'party_mode'             => (string) $template->party_mode,
            'header_display'         => (string) $template->header_display,
            'security_tier'          => (string) $template->security_tier,
            'allowed_delivery_modes' => (string) $template->allowed_delivery_modes,
            'document_type'          => $template->documentType
                ? ['slug' => $template->documentType->slug, 'label' => $template->documentType->label]
                : null,
        ];
        foreach (self::CONTENT_COLUMNS as $col) {
            $value = $this->column($template, $col);
            $body[$col] = $value === null ? null : TemplateReferenceMap::toTokens($value, $col, $nf, $fg, $removed);
        }

        $zones = [];
        foreach ($template->signatureZones as $z) {
            $zones[] = [
                'page_index'       => (int) $z->page_index,
                'x_position'       => (string) $z->x_position,
                'y_position'       => (string) $z->y_position,
                'width'            => (string) $z->width,
                'height'           => (string) $z->height,
                'type'             => $z->type,
                'assigned_parties' => $z->assigned_parties,
                'label'            => $z->label,
                'required'         => (bool) $z->required,
                'sort_order'       => (int) $z->sort_order,
            ];
        }

        $files = [];
        if ($template->render_type === 'pdf') {
            for ($i = 0; $i < (int) $template->page_count; $i++) {
                $path = "docuperfect/templates/{$template->id}/page-{$i}.png";
                if (! Storage::exists($path)) {
                    throw new TemplateTransferException('This template cannot be exported: the image for page ' . ($i + 1) . ' is missing on this system. Re-upload the pages first.');
                }
                $files["files/page-{$i}.png"] = (string) Storage::get($path);
            }
        }

        foreach ($this->unembeddedImages($body) as $src) {
            $warnings[] = "The wording uses an image from \"{$src}\" which is not carried in the package.";
        }

        $data = [
            'template'          => $body,
            'named_fields'      => $nfDefs,
            'field_groups'      => $fgDefs,
            'signature_zones'   => $zones,
            'removed_identifiers' => array_values(array_unique($removed)),
            'warnings'          => array_values(array_unique($warnings)),
        ];

        $built = TemplatePackage::build($data, $files, (string) $template->name, $this->sourceLabel());

        TemplateTransferLog::create([
            'direction'        => 'export',
            'outcome'          => 'success',
            'actor_user_id'    => $actor->id,
            'actor_name'       => $actor->name,
            'source_agency_id' => $template->agency_id,
            'template_name'    => $template->name,
            'template_id'      => $template->id,
            'package_checksum' => $built['checksum'],
            'format_version'   => TemplatePackage::VERSION,
            'source_label'     => $this->sourceLabel(),
            'warnings'         => $data['warnings'] ?: null,
        ]);

        event(new TemplatePackageExported((int) $template->id, $template->agency_id ? (int) $template->agency_id : null, $actor->id, $built['checksum']));

        return [
            'filename' => (Str::slug((string) $template->name) ?: 'template') . '-' . now()->format('Ymd') . '.cxpkg',
            'bytes'    => $built['bytes'],
            'checksum' => $built['checksum'],
            'warnings' => $data['warnings'],
        ];
    }

    /** @throws TemplateTransferException */
    private function assertExportable(Template $template): void
    {
        if ($template->trashed()) {
            throw new TemplateTransferException('This template has been deleted and cannot be exported.');
        }
        if ($template->render_type !== 'web') {
            return;
        }
        $editor = $this->column($template, 'editor_state') ?? [];
        $cds = $this->column($template, 'cds_json') ?? [];
        $hasWording = (is_string($editor['tagged_html'] ?? null) && trim($editor['tagged_html']) !== '')
            || ! empty($cds['sections']);
        if (! $hasWording) {
            throw new TemplateTransferException('This template cannot be exported: its wording is held only in a generated page file, which cannot be moved safely. Open it in the template builder and save it once, then export it again.');
        }
    }

    /**
     * A content column as an array. A few legacy rows hold JSON that was encoded twice (the
     * column then reads back as a string); decode it once more so the package carries the real
     * structure rather than a string nobody can remap.
     */
    private function column(Template $template, string $col): ?array
    {
        $value = $template->{$col};
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /** @return string[] distinct non-embedded image sources (max 5) */
    private function unembeddedImages(array $body): array
    {
        $found = [];
        $walk = function (mixed $n) use (&$walk, &$found): void {
            if (is_string($n)) {
                if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $n, $m)) {
                    foreach ($m[1] as $src) {
                        if (! str_starts_with(strtolower($src), 'data:')) {
                            $found[$src] = true;
                        }
                    }
                }
            } elseif (is_array($n)) {
                foreach ($n as $v) {
                    $walk($v);
                }
            }
        };
        $walk($body);

        return array_slice(array_keys($found), 0, 5);
    }

    private function sourceLabel(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'unknown');
    }
}
