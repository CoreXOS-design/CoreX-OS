<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use App\Events\Docuperfect\TemplatePackageImported;
use App\Models\Agency;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Models\User;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Validate, preview and import e-sign template packages. NEVER overwrites: every
 * import INSERTs a new, agency-owned template (so documents already sent — which
 * freeze their own copy — cannot be affected). One transaction; a failure removes
 * every file written. Spec: .ai/specs/esign-template-transfer.md §4.
 */
final class TemplatePackageImporter
{
    public const CHOICE_NEW_VERSION = 'new_version';
    public const CHOICE_NEW_COPY = 'new_copy';

    private const SOURCE_TYPES = ['property', 'contact', 'agent', 'deal', 'static', 'computed', 'manual'];
    private const CONTENT_COLUMNS = [
        'signing_parties', 'cds_json', 'editor_state', 'fields_json',
        'field_mappings', 'wizard_config', 'sections', 'insertable_blocks',
    ];

    // ── 1. LOAD + VALIDATE (no writes) ─────────────────────────────────────

    /**
     * Open an uploaded file (single package or bundle) and validate every package in
     * it. Returns one entry per package. Anything wrong throws a plain-language
     * TemplateTransferException and nothing is created.
     *
     * @return list<array{filename:string, manifest:array, data:array, files:array<string,string>, checksum:string}>
     * @throws TemplateTransferException
     */
    public function load(string $path): array
    {
        $loaded = [];
        if (TemplatePackage::isBundle($path)) {
            foreach (TemplatePackage::readBundle($path) as $filename => $bytes) {
                $loaded[] = ['filename' => $filename] + $this->readBytes($bytes);
            }
        } else {
            $loaded[] = ['filename' => basename($path)] + TemplatePackage::read($path);
        }

        foreach ($loaded as $i => $pkg) {
            $errors = $this->validateContent($pkg['data'], $pkg['files']);
            if ($errors) {
                $label = count($loaded) > 1 ? 'The package "' . ($pkg['data']['template']['name'] ?? $pkg['filename']) . '"' : 'This package';
                throw new TemplateTransferException($label . ' cannot be imported:', $errors);
            }
        }

        return $loaded;
    }

    /**
     * Read-only preview of what an import would do.
     *
     * @param list<array> $loaded  from load()
     * @return list<array<string,mixed>>
     */
    public function preview(array $loaded, ?Agency $target): array
    {
        $out = [];
        foreach ($loaded as $i => $pkg) {
            $t = $pkg['data']['template'];
            $blocking = [];
            $needsAck = [];

            // Document type (by slug)
            $dt = $t['document_type'] ?? null;
            $docType = ['status' => 'none', 'slug' => null, 'label' => null];
            if (is_array($dt) && ($dt['slug'] ?? '') !== '') {
                $found = DocumentType::where('slug', $dt['slug'])->first();
                $docType = ['status' => $found ? 'found' : 'missing', 'slug' => $dt['slug'], 'label' => $dt['label'] ?? $dt['slug']];
                if (! $found) {
                    if (in_array($dt['slug'], self::ectaBlockedSlugs(), true)) {
                        $blocking[] = 'The document type "' . $docType['label'] . '" does not exist on this system, and it is a type that carries the e-sign legal warning, and that warning is attached through the document type. Add the document type first, then import again.';
                    } else {
                        $needsAck[] = 'The document type "' . $docType['label'] . '" does not exist on this system. The template can be imported without a document type (you can set it afterwards).';
                    }
                }
            }

            // Field definitions
            $fieldsExisting = [];
            $fieldsNew = [];
            foreach ($pkg['data']['named_fields'] ?? [] as $def) {
                if ($this->findNamedField($def)) {
                    $fieldsExisting[] = $def['name'];
                } else {
                    $fieldsNew[] = $def['name'];
                }
            }

            // Field groups (need the target agency to know)
            $groupsExisting = [];
            $groupsNew = [];
            $warnings = $pkg['data']['warnings'] ?? [];
            if ($target) {
                foreach ($pkg['data']['field_groups'] ?? [] as $def) {
                    $existing = $this->findFieldGroup($def['name'], $target);
                    if ($existing) {
                        $groupsExisting[] = $def['name'];
                        if ($this->groupSignature($existing->fields ?? []) !== $this->groupSignatureFromDef($def, $pkg['data']['named_fields'] ?? [])) {
                            $warnings[] = 'The agency already has a field group called "' . $def['name'] . '" with different fields; the existing one will be used.';
                        }
                    } else {
                        $groupsNew[] = $def['name'];
                    }
                }
            }

            // A template of a flagged document type (sale agreement, OTP, deed) is always imported
            // with e-signing OFF: an acknowledgement belongs to the admin who gives it in setup, and
            // a package cannot carry one. Asked of the document-type flag (data), not a code list.
            $esignOff = ! empty($t['is_esign']) && app(\App\Services\Docuperfect\EsignAcknowledgementService::class)
                ->typeRequiresAcknowledgement(
                    $docType['status'] === 'found' ? DocumentType::where('slug', $docType['slug'])->value('id') : null,
                    (string) $t['name'],
                );

            // Name clash in the target agency
            $clash = ['exists' => false, 'archived' => false];
            if ($target) {
                $same = $this->sameNameTemplates($target, (string) $t['name']);
                $clash = ['exists' => $same->isNotEmpty(), 'archived' => $same->isNotEmpty() && $same->every(fn ($x) => $x->archived_at !== null)];
            }

            $out[] = [
                'index'           => $i,
                'filename'        => $pkg['filename'],
                'checksum'        => $pkg['checksum'],
                'source_label'    => $pkg['manifest']['source_label'] ?? null,
                'exported_at'     => $pkg['manifest']['exported_at'] ?? null,
                'name'            => (string) $t['name'],
                'render_type'     => $t['render_type'],
                'category'        => $t['category'],
                'template_type'   => $t['template_type'],
                'page_count'      => (int) $t['page_count'],
                'image_count'     => count($pkg['files']),
                'zone_count'      => count($pkg['data']['signature_zones'] ?? []),
                'roles'           => array_values(array_filter((array) ($t['signing_parties'] ?? []), 'is_string')),
                'tag_count'       => count((array) ($t['editor_state']['tags'] ?? [])),
                'is_esign'        => (bool) $t['is_esign'],
                'esign_switched_off' => $esignOff,
                'document_type'   => $docType,
                'fields_existing' => $fieldsExisting,
                'fields_new'      => $fieldsNew,
                'groups_existing' => $groupsExisting,
                'groups_new'      => $groupsNew,
                'clash'           => $clash,
                'removed_identifiers' => $pkg['data']['removed_identifiers'] ?? [],
                'warnings'        => array_values(array_unique($warnings)),
                'blocking'        => $blocking,
                'needs_ack'       => $needsAck,
            ];
        }

        return $out;
    }

    // ── 2. IMPORT (one transaction) ────────────────────────────────────────

    /**
     * @param list<array> $loaded               from load()
     * @param array<int,string> $choices        package index => new_version|new_copy (needed only on a name clash)
     * @param bool $allowMissingDocumentType    the user ticked "import without a document type"
     * @return list<Template> the NEW templates
     * @throws TemplateTransferException
     */
    public function import(array $loaded, Agency $target, User $actor, array $choices, bool $allowMissingDocumentType): array
    {
        // Refuse everything up front if any package is blocked or needs a choice nobody made.
        $previews = $this->preview($loaded, $target);
        foreach ($previews as $p) {
            if ($p['blocking']) {
                throw new TemplateTransferException('"' . $p['name'] . '" cannot be imported.', $p['blocking']);
            }
            if ($p['needs_ack'] && ! $allowMissingDocumentType) {
                throw new TemplateTransferException('"' . $p['name'] . '" needs your confirmation before it can be imported.', $p['needs_ack']);
            }
            if ($p['clash']['exists'] && ! in_array($choices[$p['index']] ?? null, [self::CHOICE_NEW_VERSION, self::CHOICE_NEW_COPY], true)) {
                throw new TemplateTransferException('The agency already has a template called "' . $p['name'] . '". Choose "new version" or "new copy" (or cancel) — nothing is ever replaced.');
            }
        }

        $written = [];     // absolute file paths / storage paths to remove on failure
        $storedPaths = []; // storage disk paths
        $created = [];

        DB::beginTransaction();
        try {
            foreach ($loaded as $i => $pkg) {
                $created[] = $this->createOne($pkg, (int) $i, $target, $actor, $choices[$i] ?? null, $written, $storedPaths);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($written as $f) {
                @unlink($f);
            }
            foreach ($storedPaths as $s) {
                Storage::delete($s);
            }
            foreach ($created as $c) {
                Storage::deleteDirectory("docuperfect/templates/{$c->id}");
            }
            Log::error('Template package import failed and was rolled back', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->logFailure($actor, $target, $loaded, 'failed', $e->getMessage());

            throw $e instanceof TemplateTransferException
                ? $e
                : new TemplateTransferException('The import could not be completed and nothing was created. Nothing in the agency was changed. If it keeps happening, tell the CoreX team.');
        }

        try {
            Artisan::call('view:clear');
        } catch (Throwable $e) {
            // non-fatal: the new view file has a brand-new name, nothing stale to clear
        }

        foreach ($created as $i => $template) {
            $pkg = $loaded[$i];
            TemplateTransferLog::create([
                'direction'          => 'import',
                'outcome'            => 'success',
                'actor_user_id'      => $actor->id,
                'actor_name'         => $actor->name,
                'target_agency_id'   => $target->id,
                'target_agency_name' => $target->name,
                'template_name'      => $template->name,
                'template_id'        => $template->id,
                'package_checksum'   => $pkg['checksum'],
                'format_version'     => TemplatePackage::VERSION,
                'source_label'       => $pkg['manifest']['source_label'] ?? null,
                'name_clash_choice'  => $previews[$i]['clash']['exists'] ? ($choices[$i] ?? null) : null,
                'warnings'           => $previews[$i]['warnings'] ?: null,
            ]);
            event(new TemplatePackageImported((int) $template->id, (int) $target->id, $actor->id, $pkg['checksum']));
        }

        return $created;
    }

    /** Record a rejected upload (validation failed before anything could be created). */
    public function logRejected(User $actor, string $reason, ?string $checksum = null, ?string $name = null): void
    {
        TemplateTransferLog::create([
            'direction'        => 'import',
            'outcome'          => 'rejected',
            'actor_user_id'    => $actor->id,
            'actor_name'       => $actor->name,
            'template_name'    => $name,
            'package_checksum' => $checksum,
            'failure_reason'   => mb_substr($reason, 0, 2000),
        ]);
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @param string[] $written       absolute paths written (removed on failure)
     * @param string[] $storedPaths   storage-disk paths written (removed on failure)
     */
    private function createOne(array $pkg, int $index, Agency $target, User $actor, ?string $choice, array &$written, array &$storedPaths): Template
    {
        $data = $pkg['data'];
        $t = $data['template'];

        // Field definitions: match by stable key, create only if absent.
        $nfMap = [];
        foreach ($data['named_fields'] ?? [] as $token => $def) {
            $row = $this->findNamedField($def) ?? NamedField::create([
                'name'                => $def['name'],
                'field_type'          => $def['field_type'] ?: 'text',
                'default_options'     => $def['default_options'] ?? null,
                'sort_order'          => (int) ($def['sort_order'] ?? 0),
                'source_type'         => $def['source_type'],
                'source_column'       => $def['source_column'] ?? null,
                'source_contact_type' => $def['source_contact_type'] ?? null,
            ]);
            $nfMap[$token] = (int) $row->id;
        }

        // Field groups, in the TARGET agency.
        $fgMap = [];
        foreach ($data['field_groups'] ?? [] as $token => $def) {
            $existing = $this->findFieldGroup($def['name'], $target);
            if (! $existing) {
                $existing = FieldGroup::withoutAgencyStamping(fn () => FieldGroup::create([
                    'agency_id'   => $target->id,
                    'created_by'  => $actor->id,
                    'name'        => $def['name'],
                    'description' => $def['description'] ?? null,
                    'fields'      => TemplateReferenceMap::fromTokens($def['members'] ?? [], $nfMap, []),
                    'layout'      => $def['layout'] ?: 'vertical',
                    'sort_order'  => (int) ($def['sort_order'] ?? 0),
                    'is_global'   => false,
                ]));
            }
            $fgMap[$token] = (int) $existing->id;
        }

        $docTypeId = null;
        if (is_array($t['document_type'] ?? null) && ($t['document_type']['slug'] ?? '') !== '') {
            $docTypeId = DocumentType::where('slug', $t['document_type']['slug'])->value('id');
        }

        $attrs = [
            'name'                   => $this->finalName($target, (string) $t['name'], $choice),
            'render_type'            => $t['render_type'],
            'template_type'          => $t['template_type'],
            'category'               => $t['category'],
            'page_count'             => (int) $t['page_count'],
            'is_esign'               => (bool) $t['is_esign'],
            'party_mode'             => $t['party_mode'],
            'header_display'         => $t['header_display'],
            'security_tier'          => $t['security_tier'],
            'allowed_delivery_modes' => $t['allowed_delivery_modes'],
            'document_type_id'       => $docTypeId,
            // Never ownerless: always the chosen agency. "All branches" is agency-internal.
            'agency_id'              => $target->id,
            'is_global'              => TemplateTransferSettings::visibility() === TemplateTransferSettings::VISIBILITY_ALL_BRANCHES,
            'owner_id'               => $actor->id,
            'archived_at'            => null,
        ];
        foreach (self::CONTENT_COLUMNS as $col) {
            $attrs[$col] = ($t[$col] ?? null) === null ? null : TemplateReferenceMap::fromTokens($t[$col], $nfMap, $fgMap);
        }

        $template = Template::create($attrs);

        foreach ($data['signature_zones'] ?? [] as $z) {
            TemplateSignatureZone::create([
                'template_id'      => $template->id,
                'page_index'       => $z['page_index'],
                'x_position'       => $z['x_position'],
                'y_position'       => $z['y_position'],
                'width'            => $z['width'],
                'height'           => $z['height'],
                'type'             => $z['type'],
                'assigned_parties' => $z['assigned_parties'],
                'label'            => $z['label'] ?? null,
                'required'         => (bool) ($z['required'] ?? true),
                'sort_order'       => (int) ($z['sort_order'] ?? 0),
            ]);
        }

        if ($template->render_type === 'pdf') {
            foreach ($pkg['files'] as $name => $bytes) {
                $path = "docuperfect/templates/{$template->id}/" . basename($name);
                $storedPaths[] = $path;
                Storage::put($path, $bytes);
            }
        } else {
            // Never write over a page file that is already there: some ids carry a committed
            // snapshot (resources/views/.../cds/template-<id>.blade.php, STANDARDS -1w) and a
            // freshly numbered template must not replace someone else's file. Refuse; the
            // transaction rolls back and nothing is created.
            $pageFile = resource_path("views/docuperfect/web-templates/cds/template-{$template->id}.blade.php");
            if (is_file($pageFile)) {
                throw new TemplateTransferException('The import could not be completed: this system already has a page file for the next template number, and it will not be overwritten. Nothing was created. Tell the CoreX team.');
            }
            $written[] = $pageFile;
            app(WebTemplateBladeEnsurer::class)->regenerate($template);
        }

        return $template->fresh();
    }

    /** Structural + safety validation of a decoded package. @return string[] */
    private function validateContent(array $data, array $files): array
    {
        $e = [];
        $t = $data['template'] ?? [];

        $name = $t['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            $e[] = 'The template has no valid name.';
        }
        if (! in_array($t['render_type'] ?? null, ['pdf', 'web'], true)) {
            $e[] = 'The template kind must be "pdf" or "web".';
        }
        if (! is_string($t['template_type'] ?? null) || mb_strlen($t['template_type']) > 255) {
            $e[] = 'The template type is missing or too long.';
        }
        if (($t['category'] ?? null) !== null && ! in_array($t['category'], ['sales', 'rentals'], true)) {
            $e[] = 'The category must be sales or rentals.';
        }
        if (! is_int($t['page_count'] ?? null) || $t['page_count'] < 0 || $t['page_count'] > 500) {
            $e[] = 'The page count is not valid.';
        }
        if (! is_bool($t['is_esign'] ?? null)) {
            $e[] = 'The e-sign setting is missing.';
        }
        foreach (['party_mode', 'header_display', 'security_tier'] as $k) {
            if (! is_string($t[$k] ?? null) || ! preg_match('/^[a-z_]{1,20}$/', $t[$k])) {
                $e[] = "The setting \"{$k}\" is missing or not valid.";
            }
        }
        if (! is_string($t['allowed_delivery_modes'] ?? null) || ! preg_match('/^[a-z_]+(,[a-z_]+)*$/', $t['allowed_delivery_modes']) || strlen($t['allowed_delivery_modes']) > 100) {
            $e[] = 'The allowed delivery modes are not valid.';
        }
        foreach (self::CONTENT_COLUMNS as $col) {
            if (array_key_exists($col, $t) && $t[$col] !== null && ! is_array($t[$col])) {
                $e[] = "The template part \"{$col}\" is damaged.";
            }
        }
        $dt = $t['document_type'] ?? null;
        if ($dt !== null && (! is_array($dt) || ! is_string($dt['slug'] ?? null) || ! preg_match('/^[a-z0-9_\-]{1,100}$/i', $dt['slug']))) {
            $e[] = 'The document type reference is not valid.';
        }

        if (($t['render_type'] ?? null) === 'web') {
            $editor = is_array($t['editor_state'] ?? null) ? $t['editor_state'] : [];
            $cds = is_array($t['cds_json'] ?? null) ? $t['cds_json'] : [];
            if (! (is_string($editor['tagged_html'] ?? null) && trim($editor['tagged_html']) !== '') && empty($cds['sections'])) {
                $e[] = 'The template has no wording to import.';
            }
        } elseif (($t['render_type'] ?? null) === 'pdf') {
            $pages = (int) ($t['page_count'] ?? 0);
            for ($i = 0; $i < $pages; $i++) {
                if (! isset($files["files/page-{$i}.png"])) {
                    $e[] = 'The image for page ' . ($i + 1) . ' is missing from the package.';
                    break;
                }
            }
            if (count($files) > $pages) {
                $e[] = 'The package holds more page images than the template has pages.';
            }
        }

        // Definitions
        foreach ((array) ($data['named_fields'] ?? []) as $token => $def) {
            if (! preg_match('/^nf\d{1,5}$/', (string) $token) || ! is_array($def) || ! is_string($def['name'] ?? null) || trim($def['name']) === '' || mb_strlen($def['name']) > 255
                || ! in_array($def['source_type'] ?? null, self::SOURCE_TYPES, true) || ! is_string($def['field_type'] ?? null)) {
                $e[] = 'A field definition in the package is damaged.';
                break;
            }
        }
        foreach ((array) ($data['field_groups'] ?? []) as $token => $def) {
            if (! preg_match('/^fg\d{1,5}$/', (string) $token) || ! is_array($def) || ! is_string($def['name'] ?? null) || trim($def['name']) === '' || mb_strlen($def['name']) > 255
                || ! in_array($def['layout'] ?? null, ['vertical', 'horizontal'], true) || ! is_array($def['members'] ?? null)) {
                $e[] = 'A field group in the package is damaged.';
                break;
            }
        }
        foreach ((array) ($data['signature_zones'] ?? []) as $z) {
            if (! is_array($z) || ! is_int($z['page_index'] ?? null) || $z['page_index'] < 0
                || ! is_numeric($z['x_position'] ?? null) || ! is_numeric($z['y_position'] ?? null) || ! is_numeric($z['width'] ?? null) || ! is_numeric($z['height'] ?? null)
                || ! in_array($z['type'] ?? null, ['signature', 'initial'], true) || ! is_array($z['assigned_parties'] ?? null)) {
                $e[] = 'A signature position in the package is damaged.';
                break;
            }
        }

        // Every id token the content uses must be defined in the package.
        $nfDefined = array_keys((array) ($data['named_fields'] ?? []));
        $fgDefined = array_keys((array) ($data['field_groups'] ?? []));
        $used = TemplateReferenceMap::referencedTokens([$t, $data['field_groups'] ?? []]);
        if (array_diff($used['nf'], $nfDefined) || array_diff($used['fg'], $fgDefined)) {
            $e[] = 'The template points at a field or field group that is not in the package.';
        }

        // Safety: this is the real gate (a checksum is not authentication).
        $e = array_merge($e, TemplateContentSafety::scan($data, 'package'), TemplateContentSafety::checkRoleTokens($t));

        return array_values(array_unique($e));
    }

    /** Package bytes -> validated parts (via a temp file; ZipArchive needs a path). */
    private function readBytes(string $bytes): array
    {
        $dir = storage_path('app/template-transfer/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = tempnam($dir, 'in');
        file_put_contents($tmp, $bytes);
        try {
            return TemplatePackage::read($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Match a field definition by its stable key (never by id). */
    private function findNamedField(array $def): ?NamedField
    {
        $q = NamedField::query()->where('source_type', $def['source_type']);
        if (($def['source_column'] ?? '') !== '' && $def['source_column'] !== null) {
            $q->where('source_column', $def['source_column']);
            ($def['source_contact_type'] ?? null) === null
                ? $q->whereNull('source_contact_type')
                : $q->where('source_contact_type', $def['source_contact_type']);
        } else {
            $q->where('name', $def['name'])->where('field_type', $def['field_type'] ?: 'text')
                ->where(fn ($w) => $w->whereNull('source_column')->orWhere('source_column', ''));
        }

        return $q->orderBy('id')->first();
    }

    /** A group of that name in the target agency, or a global one. */
    private function findFieldGroup(string $name, Agency $target): ?FieldGroup
    {
        return FieldGroup::withoutGlobalScopes()
            ->where('name', $name)
            ->where(fn ($q) => $q->where('agency_id', $target->id)->orWhere(fn ($g) => $g->whereNull('agency_id')->where('is_global', true)))
            ->orderByRaw('agency_id IS NULL')   // the agency's own first
            ->orderBy('id')
            ->first();
    }

    /** @return string[] sorted stable keys of a stored group's members */
    private function groupSignature(array $members): array
    {
        $ids = [];
        array_walk_recursive($members, function ($v, $k) use (&$ids) {
            if (in_array($k, TemplateReferenceMap::NAMED_FIELD_KEYS, true) && $v) {
                $ids[] = (int) $v;
            }
        });
        $keys = [];
        foreach (NamedField::withTrashed()->whereIn('id', $ids)->get() as $nf) {
            $keys[] = $nf->source_type . '|' . $nf->source_column . '|' . $nf->source_contact_type . '|' . ($nf->source_column ? '' : $nf->name);
        }
        sort($keys);

        return $keys;
    }

    /** @return string[] same shape, from a package group definition */
    private function groupSignatureFromDef(array $def, array $namedFields): array
    {
        $tokens = TemplateReferenceMap::referencedTokens($def['members'] ?? [])['nf'];
        $keys = [];
        foreach ($tokens as $tk) {
            $n = $namedFields[$tk] ?? null;
            if ($n) {
                $keys[] = $n['source_type'] . '|' . ($n['source_column'] ?? '') . '|' . ($n['source_contact_type'] ?? '') . '|' . (($n['source_column'] ?? '') !== '' ? '' : $n['name']);
            }
        }
        sort($keys);

        return $keys;
    }

    /** Non-deleted templates (active or archived) in the agency with this name. */
    private function sameNameTemplates(Agency $agency, string $name)
    {
        return Template::query()->where('agency_id', $agency->id)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->get(['id', 'name', 'archived_at']);
    }

    private function finalName(Agency $agency, string $name, ?string $choice): string
    {
        $name = trim($name);
        $taken = Template::query()->where('agency_id', $agency->id)->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip();

        if (! isset($taken[mb_strtolower($name)])) {
            return $name;
        }

        $base = mb_substr($name, 0, 190);

        if ($choice === self::CHOICE_NEW_VERSION) {
            $pattern = TemplateTransferSettings::pattern('version_suffix');
            $regex = '/^' . preg_quote(mb_strtolower($base) . ' ', '/') . str_replace(preg_quote('{n}', '/'), '(\d+)', preg_quote(mb_strtolower($pattern), '/')) . '$/u';
            $max = 1;
            foreach ($taken->keys() as $existing) {
                if (preg_match($regex, (string) $existing, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }

            return $base . ' ' . str_replace('{n}', (string) ($max + 1), $pattern);
        }

        $candidate = $base . ' ' . str_replace('{date}', now()->format('d M Y'), TemplateTransferSettings::pattern('copy_suffix'));
        $n = 2;
        $unique = $candidate;
        while (isset($taken[mb_strtolower($unique)])) {
            $unique = $candidate . ' ' . $n++;
        }

        return $unique;
    }

    /** @return string[] */
    private static function ectaBlockedSlugs(): array
    {
        return ['otp', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation', 'offer_to_purchase'];
    }

    private function logFailure(User $actor, Agency $target, array $loaded, string $outcome, string $reason): void
    {
        foreach ($loaded as $pkg) {
            try {
                TemplateTransferLog::create([
                    'direction'          => 'import',
                    'outcome'            => $outcome,
                    'actor_user_id'      => $actor->id,
                    'actor_name'         => $actor->name,
                    'target_agency_id'   => $target->id,
                    'target_agency_name' => $target->name,
                    'template_name'      => $pkg['data']['template']['name'] ?? null,
                    'package_checksum'   => $pkg['checksum'],
                    'format_version'     => TemplatePackage::VERSION,
                    'source_label'       => $pkg['manifest']['source_label'] ?? null,
                    'failure_reason'     => mb_substr($reason, 0, 2000),
                ]);
            } catch (Throwable $e) {
                Log::warning('Could not write template_transfer_log failure row: ' . $e->getMessage());
            }
        }
    }
}
