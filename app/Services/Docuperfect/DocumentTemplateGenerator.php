<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\ImportDraft;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentTemplateGenerator
{
    /**
     * Generate a web template from an import draft's tag-based data.
     *
     * The draft's fields_json must contain:
     *   - tags: array of tag objects [{id, type, number, label}, ...]
     *   - mappings: object keyed by tag ID with mapping details
     *   - tagged_html: the document HTML with tag spans inserted
     *
     * @param ImportDraft $draft    The import draft with tagged data
     * @param string      $templateName  Human-readable template name
     * @param int         $ownerId       User ID of the creator
     * @return Template
     */
    public function generate(ImportDraft $draft, string $templateName, int $ownerId): Template
    {
        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        $tags = $fieldsData['tags'] ?? [];
        $mappings = $fieldsData['mappings'] ?? [];
        $taggedHtml = $fieldsData['tagged_html'] ?? '';

        Log::info('Generator: input state', [
            'tagged_html_length' => strlen($taggedHtml ?? ''),
            'tag_count' => count($tags ?? []),
            'mapping_count' => count($mappings ?? []),
        ]);

        if (empty($taggedHtml)) {
            throw new \RuntimeException('Draft has no tagged HTML. Complete tagging before generating.');
        }

        if (empty($tags)) {
            throw new \RuntimeException('Draft has no tags. Tag fields before generating.');
        }

        // Index tags by ID for quick lookup
        $tagsById = collect($tags)->keyBy('id')->all();

        // Pre-load named fields we'll need
        try {
            $namedFieldIds = collect($mappings)
                ->pluck('namedFieldId')
                ->filter()
                ->unique()
                ->values()
                ->all();
            $namedFieldMap = NamedField::whereIn('id', $namedFieldIds)->get()->keyBy('id');
        } catch (\Exception $e) {
            Log::error('Generator: named field pre-load failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Pre-load field groups we'll need
        try {
            $fieldGroupIds = collect($mappings)
                ->pluck('fieldGroupId')
                ->filter()
                ->unique()
                ->values()
                ->all();
            $fieldGroupMap = FieldGroup::whereIn('id', $fieldGroupIds)->get()->keyBy('id');
        } catch (\Exception $e) {
            Log::error('Generator: field group pre-load failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Also load named fields referenced inside field groups
        $groupFieldIds = [];
        foreach ($fieldGroupMap as $fg) {
            foreach (($fg->fields ?? []) as $f) {
                if (!empty($f['named_field_id'])) {
                    $groupFieldIds[] = $f['named_field_id'];
                }
            }
        }
        if (!empty($groupFieldIds)) {
            $extraFields = NamedField::whereIn('id', $groupFieldIds)
                ->whereNotIn('id', $namedFieldIds)
                ->get()
                ->keyBy('id');
            $namedFieldMap = $namedFieldMap->union($extraFields);
        }

        // Strip any existing blade shell from tagged HTML (prevents nesting on re-edit)
        $taggedHtml = $this->extractBodyOnly($taggedHtml);

        // Step 2: Process tag spans → proper field/sig/ini spans
        try {
            $processedHtml = $this->processTagSpans($taggedHtml, $tagsById, $mappings, $namedFieldMap, $fieldGroupMap);
        } catch (\Exception $e) {
            Log::error('Generator: processTagSpans failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        Log::info('Generator: after processTagSpans', [
            'processed_html_length' => strlen($processedHtml),
        ]);

        // Step 3: Collect signing parties from SIG tags
        try {
            $signingParties = $this->collectSigningParties($tags, $mappings);
        } catch (\Exception $e) {
            Log::error('Generator: collectSigningParties failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Strip any shell from processed HTML before wrapping (safety guard)
        $processedHtml = $this->extractBodyOnly($processedHtml);

        // Stamp the data-role-block contract onto the generated document.
        //
        // THIS IS WHERE THE CONTRACT IS BORN ON THE IMPORT PATH. processTagSpans() has just
        // written the `data-field` + `data-contact-type` spans, which is exactly what the
        // normalizer needs — and this is the only moment they exist before the blade is
        // written. The CDS *builder* path normalises at publish (TemplateController::cdsGenerate),
        // but the *importer* path never did, so every imported document went out without the
        // contract and rendered through legacy clustering forever after. That is why the
        // backfill found nothing to do: there was never anything to find.
        //
        // Stamp it here and an imported document carries the contract from birth.
        try {
            $processedHtml = app(RoleBlockNormalizer::class)->normalize($processedHtml);
            Log::info('Generator: role-block contract stamped', [
                'role_blocks' => substr_count($processedHtml, 'data-role-block='),
            ]);
        } catch (\Throwable $e) {
            // Never fail an import over the contract — it can be backfilled.
            Log::error('Generator: role-block normalisation failed', ['error' => $e->getMessage()]);
        }

        // Step 4: Wrap in blade shell
        try {
            $bladeContent = $this->wrapInBladeTemplate($processedHtml, $templateName);
        } catch (\Exception $e) {
            Log::error('Generator: wrapInBladeTemplate failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Validate: detect shell nesting (should never happen after extractBodyOnly)
        $docCount = substr_count($bladeContent, 'DOCTYPE');
        $headerCount = substr_count($bladeContent, 'company-header');
        if ($docCount > 1 || $headerCount > 1) {
            throw new \RuntimeException(
                "Generated blade has {$docCount} DOCTYPEs and {$headerCount} company-headers — shell nesting detected. Aborting."
            );
        }

        Log::info('Generator: after wrap', [
            'blade_length' => strlen($bladeContent),
        ]);

        // Step 5: Save blade file — ONE file, slug-based, no timestamp
        // Clean non-breaking spaces, soft hyphens, and smart quotes from output
        $bladeContent = str_replace("\xc2\xa0", ' ', $bladeContent);       // UTF-8 NBSP
        $bladeContent = str_replace("\xc2\xad", '', $bladeContent);        // soft hyphen
        $bladeContent = str_replace('&nbsp;', ' ', $bladeContent);         // entity NBSP
        $bladeContent = str_replace(["\xe2\x80\x98", "\xe2\x80\x99"], "'", $bladeContent); // smart single quotes
        $bladeContent = str_replace(["\xe2\x80\x9c", "\xe2\x80\x9d"], '"', $bladeContent); // smart double quotes

        // Preserve full editor state for lossless round-tripping via editFromTemplate()
        $editorState = [
            'tags'        => $tags,
            'mappings'    => $mappings,
            'tagged_html' => $taggedHtml,
        ];

        // Step 6: Build fields_json for template record
        try {
            $fieldsJson = $this->buildFieldsJson($tags, $mappings, $namedFieldMap, $fieldGroupMap);
        } catch (\Exception $e) {
            Log::error('Generator: buildFieldsJson failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Step 7: Create or update template record
        $sourceTemplateId = $fieldsData['source_template_id'] ?? null;

        try {
            if ($sourceTemplateId) {
                // Editing an existing template — keep its existing blade path
                $template = Template::findOrFail($sourceTemplateId);

                // Reuse existing slug/path to avoid file proliferation
                $bladeRelPath = $template->blade_view;
                $bladeFilePath = resource_path('views/' . str_replace('.', '/', $bladeRelPath) . '.blade.php');

                $template->update([
                    'name' => $templateName,
                    'blade_view' => $bladeRelPath,
                    'fields_json' => $fieldsJson,
                    'signing_parties' => $signingParties,
                    'editor_state' => $editorState,
                ]);
            } else {
                // Brand new template — generate unique slug
                $slug = Str::slug($templateName);
                $bladeFilePath = resource_path("views/docuperfect/web-templates/imported/{$slug}.blade.php");

                // Create the template first to get an ID for slug uniqueness
                $template = Template::create([
                    'name' => $templateName,
                    'template_type' => 'general',
                    'render_type' => 'web',
                    'blade_view' => "docuperfect.web-templates.imported.{$slug}",
                    'page_count' => 1,
                    'fields_json' => $fieldsJson,
                    'signing_parties' => $signingParties,
                    'editor_state' => $editorState,
                    'is_global' => false,
                    // Was an unconditional `true` — for EVERY imported document, an Offer To
                    // Purchase included. The importer cannot know whether a document may
                    // lawfully be e-signed, so it must not assert that it can.
                    // Template::booted() forces this to false for any alienation document
                    // (ECTA §13(1)); this stops the importer claiming otherwise to begin with.
                    'is_esign' => true,
                    'owner_id' => $ownerId,
                ]);

                // If blade file already exists (another template uses this slug), make it unique
                if (file_exists($bladeFilePath)) {
                    $slug = $slug . '-' . $template->id;
                    $bladeFilePath = resource_path("views/docuperfect/web-templates/imported/{$slug}.blade.php");
                    $template->update(['blade_view' => "docuperfect.web-templates.imported.{$slug}"]);
                }

                $bladeRelPath = $template->blade_view;
            }
        } catch (\Exception $e) {
            Log::error('Generator: Template save failed', ['error' => $e->getMessage(), 'source_template_id' => $sourceTemplateId]);
            throw $e;
        }

        // Write blade file — ensure directory exists and is writable
        $dir = dirname($bladeFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!is_writable($dir)) {
            chmod($dir, 0775);
        }

        try {
            file_put_contents($bladeFilePath, $bladeContent);
        } catch (\Exception $e) {
            Log::error('Generator: file_put_contents failed', ['error' => $e->getMessage(), 'path' => $bladeFilePath]);
            throw $e;
        }

        Log::info('Generator: blade file written', [
            'path' => $bladeFilePath,
            'exists' => file_exists($bladeFilePath),
        ]);

        Log::info('Generator: template record saved', [
            'template_id' => $template->id,
            'mode' => $sourceTemplateId ? 'updated' : 'created',
        ]);

        return $template;
    }

    /**
     * Replace tag spans in tagged HTML with proper field/sig/ini elements.
     */
    protected function processTagSpans(
        string $html,
        array $tagsById,
        array $mappings,
        \Illuminate\Support\Collection $namedFieldMap,
        \Illuminate\Support\Collection $fieldGroupMap
    ): string {
        return preg_replace_callback(
            '/<span[^>]*\bdata-tag-id="([^"]+)"[^>]*>.*?<\/span>/s',
            function ($match) use ($tagsById, $mappings, $namedFieldMap, $fieldGroupMap) {
                $tagId = $match[1];
                $tag = $tagsById[$tagId] ?? null;
                $mapping = $mappings[$tagId] ?? null;

                // A TAG WE DO NOT UNDERSTAND MUST NEVER BE DELETED.
                //
                // Both the orphan path and the unknown-type path used to `return ''` — which
                // does not "skip" the tag, it ERASES THE BLANK FROM THE DOCUMENT. The import
                // then completes, reports success, and produces a legal template whose
                // fill-in lines have quietly disappeared: the text reads "I / We  the
                // undersigned" with nothing to sign into, and nobody is told. A document that
                // silently loses its blanks is worse than an import that fails.
                //
                // (Hit for real: a tag carrying no `type` fell straight through to the
                // unknown-type branch, and all 39 blanks were erased from the EATS.)
                //
                // Prevent-or-absorb (BUILD_STANDARD §3): absorb it as a manual field the agent
                // can still fill, and say so loudly. Never destroy content.
                if (!$tag) {
                    Log::error('Generator: tag span with no matching tag — preserved as a manual field, NOT deleted', [
                        'tag_id' => $tagId,
                    ]);

                    return $this->renderManualFallbackField($tagId, 'Unmapped field');
                }

                // A tag that exists in the document IS a blank. If it never got a type, the
                // overwhelmingly common case is an input — default to it rather than erase it.
                $tagType = $tag['type'] ?? '';
                if ($tagType === '') {
                    Log::warning('Generator: tag has no type — defaulting to input rather than dropping the blank', [
                        'tag_id' => $tagId,
                    ]);
                    $tagType = 'input';
                }

                if ($tagType === 'input') {
                    return $this->renderInputTag($mapping, $namedFieldMap, $fieldGroupMap, (int) ($tag['number'] ?? 0));
                }

                if ($tagType === 'signature') {
                    return $this->renderSigTag($tag, $mapping);
                }

                if ($tagType === 'initial') {
                    return $this->renderIniTag($tag, $mapping);
                }

                Log::error('Generator: unknown tag type — preserved as a manual field, NOT deleted', [
                    'tag_id' => $tagId,
                    'type'   => $tagType,
                ]);

                return $this->renderManualFallbackField($tagId, 'Unrecognised field');
            },
            $html
        );
    }

    /**
     * The blank survives, even when we cannot make sense of the tag.
     *
     * An agent can fill a manual field. Nobody can fill a blank that was deleted.
     */
    protected function renderManualFallbackField(string $tagId, string $label): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($tagId));
        $slug = trim((string) $slug, '_') ?: 'unknown';

        return '<span class="field field-manual" data-field="manual.' . e($slug) . '"'
            . ' data-label="' . e($label) . '">{{ $manual_' . $slug . ' ?? \'\' }}</span>';
    }

    /**
     * Render an INPUT tag as a field span based on its mapping.
     */
    protected function renderInputTag(
        ?array $mapping,
        \Illuminate\Support\Collection $namedFieldMap,
        \Illuminate\Support\Collection $fieldGroupMap,
        int $tagNumber = 0
    ): string {
        if (!$mapping || empty($mapping['mappingType'])) {
            $fieldKey = 'field_' . $tagNumber;
            return '<span class="field field-manual" data-field="manual.' . $fieldKey . '" data-label="Field ' . $tagNumber . '">{{ $manual_' . $fieldKey . ' ?? \'\' }}</span>';
        }

        $mappingType = $mapping['mappingType'];

        if ($mappingType === 'named_field') {
            $nfId = $mapping['namedFieldId'] ?? null;
            $nf = $nfId ? $namedFieldMap->get($nfId) : null;

            if (!$nf) {
                $fieldKey = 'field_' . $tagNumber;
                $fallbackLabel = $mapping['label'] ?? 'Field ' . $tagNumber;
                return '<span class="field field-manual" data-field="manual.' . $fieldKey . '" data-label="' . e($fallbackLabel) . '">{{ $manual_' . $fieldKey . ' ?? \'\' }}</span>';
            }

            $sourceType = $nf->source_type ?? 'manual';
            $sourceColumn = $nf->source_column ?? 'unknown';
            $contactType = $nf->source_contact_type ?? '';
            $dataField = $sourceType . '.' . $sourceColumn;
            $varName = $this->columnToBladeVar($sourceColumn);
            $nfLabel = $nf->name ?? '';

            $contactAttr = $contactType
                ? ' data-contact-type="' . e($contactType) . '"'
                : '';

            return '<span class="field" data-field="' . e($dataField) . '"' . $contactAttr . ' data-label="' . e($nfLabel) . '">{{ $' . $varName . ' ?? \'\' }}</span>';
        }

        if ($mappingType === 'field_group') {
            $fgId = $mapping['fieldGroupId'] ?? null;
            $fg = $fgId ? $fieldGroupMap->get($fgId) : null;

            if (!$fg) {
                $fieldKey = 'field_' . $tagNumber;
                $fallbackLabel = $mapping['label'] ?? 'Field ' . $tagNumber;
                return '<span class="field field-manual" data-field="manual.' . $fieldKey . '" data-label="' . e($fallbackLabel) . '">{{ $manual_' . $fieldKey . ' ?? \'\' }}</span>';
            }

            $layout = $fg->layout ?? 'inline';
            $groupId = $fg->id;
            $inner = '';

            foreach (($fg->fields ?? []) as $f) {
                $nfId = $f['named_field_id'] ?? null;
                $nf = $nfId ? $namedFieldMap->get($nfId) : null;

                if (!$nf) continue;

                $sourceType = $nf->source_type ?? 'manual';
                $sourceColumn = $nf->source_column ?? 'unknown';
                $contactType = $nf->source_contact_type ?? '';
                $dataField = $sourceType . '.' . $sourceColumn;
                $varName = $this->columnToBladeVar($sourceColumn);
                $label = $f['label_override'] ?? $nf->name ?? '';

                $contactAttr = $contactType
                    ? ' data-contact-type="' . e($contactType) . '"'
                    : '';

                $inner .= '<span class="field" data-field="' . e($dataField) . '"' . $contactAttr
                    . ' data-label="' . e($label) . '">{{ $' . $varName . ' ?? \'\' }}</span> ';
            }

            return '<span class="field-group" data-group-id="' . $groupId . '" data-layout="' . e($layout) . '">'
                . trim($inner)
                . '</span>';
        }

        if ($mappingType === 'manual') {
            $label = $mapping['manualLabel'] ?? $mapping['label'] ?? 'custom';
            $slug = Str::slug($label, '_');
            $varName = 'manual_' . $slug;

            return '<span class="field field-manual" data-field="manual.' . e($slug) . '" data-label="' . e($label) . '">{{ $' . $varName . ' ?? \'\' }}</span>';
        }

        return '';
    }

    /**
     * Render a SIG tag as a signature block with variant and parties.
     * Actual line counts are resolved at document creation from live contact data.
     * Template output shows one placeholder line per party type.
     */
    protected function renderSigTag(array $tag, ?array $mapping): string
    {
        $parties = $mapping['parties'] ?? [];
        $variant = $mapping['variant'] ?? 'sig_only';
        $number = $tag['number'] ?? 1;

        if (empty($parties)) {
            $parties = ['Agent'];
        }

        // Sort: other parties first, Agent always last
        $parties = collect($parties)
            ->sortBy(fn($p) => strtolower($p) === 'agent' ? 1 : 0)
            ->values()
            ->toArray();

        $partiesJson = e(json_encode($parties));
        $html = '<div class="sig-block" data-sig-number="' . $number . '" data-variant="' . e($variant) . '" data-parties="' . $partiesJson . '">';

        // Preamble based on variant
        if ($variant === 'sig_full') {
            $html .= '<p class="sig-preamble">'
                . 'This agreement has been accepted and signed at '
                . '<span class="field field-tiny">{{ $signedAt ?? \'\' }}</span> '
                . 'on the <span class="field field-short">{{ $signedDay ?? \'\' }}</span> '
                . 'day of <span class="field field-medium">{{ $signedMonth ?? \'\' }}</span> '
                . '<span class="field field-tiny">{{ $signedYear ?? \'\' }}</span>'
                . '</p>';
        } elseif ($variant === 'sig_with_location') {
            $html .= '<p class="sig-preamble">'
                . 'Signed at <span class="field field-tiny">{{ $signedAt ?? \'\' }}</span> '
                . 'on <span class="field field-short">{{ $signedDate ?? \'\' }}</span>'
                . '</p>';
        }

        // One signature placeholder per party type
        foreach ($parties as $partyName) {
            $varName = Str::camel(Str::slug($partyName, '_')) . 'Name';
            $html .= '<div class="sig-block-party">'
                . '<div class="sig-line">_________________________________</div>'
                . '<div class="sig-name">{{ $' . $varName . ' ?? \'' . e($partyName) . '\' }}</div>'
                . '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render an INI tag as initial placeholders for each party.
     */
    protected function renderIniTag(array $tag, ?array $mapping): string
    {
        $parties = $mapping['parties'] ?? [];
        $number = $tag['number'] ?? 1;

        if (empty($parties)) {
            $parties = ['Agent'];
        }

        $partiesJson = e(json_encode($parties));
        $html = '<span class="ini-placeholder" data-ini-number="' . $number . '" data-parties="' . $partiesJson . '">';

        $labels = array_map(fn($p) => e($p), $parties);
        $html .= '[INITIAL: ' . implode(', ', $labels) . ']';
        $html .= '</span>';

        return $html;
    }

    /**
     * Collect unique signing parties from SIG tags, in order.
     * Other parties appear first in document order, Agent always last.
     */
    protected function collectSigningParties(array $tags, array $mappings): array
    {
        $parties = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (($tag['type'] ?? '') !== 'signature') continue;

            $tagId = $tag['id'] ?? '';
            $mapping = $mappings[$tagId] ?? null;
            $tagParties = $mapping['parties'] ?? [];

            // Backwards compat: single 'party' key from old drafts
            if (empty($tagParties) && !empty($mapping['party'])) {
                $tagParties = [$mapping['party']];
            }

            foreach ($tagParties as $party) {
                if ($party && !isset($seen[$party])) {
                    $seen[$party] = true;
                    $parties[] = $party;
                }
            }
        }

        // Sort: all parties in natural order, Agent always last
        $parties = collect($parties)
            ->sortBy(fn($p) => strtolower($p) === 'agent' ? 1 : 0)
            ->values()
            ->toArray();

        // Default if no SIG tags
        if (empty($parties)) {
            $parties = ['Agent'];
        }

        return $parties;
    }

    /**
     * Build fields_json array for the template record.
     *
     * Each entry includes 'field_name' matching the Blade variable name
     * used in the generated template — required by webPreview() to populate
     * placeholder values.
     */
    protected function buildFieldsJson(
        array $tags,
        array $mappings,
        \Illuminate\Support\Collection $namedFieldMap,
        \Illuminate\Support\Collection $fieldGroupMap
    ): array {
        $fields = [];

        foreach ($tags as $tag) {
            $tagId = $tag['id'] ?? '';
            $tagType = $tag['type'] ?? '';
            $tagNumber = $tag['number'] ?? 0;
            $mapping = $mappings[$tagId] ?? [];

            $mappingType = $mapping['mappingType'] ?? '';
            $party = $mapping['party'] ?? '';
            $label = $mapping['label'] ?? '';
            $fieldName = '';

            // Resolve data_field and field_name (blade variable)
            $dataField = '';
            if ($mappingType === 'named_field') {
                $nfId = $mapping['namedFieldId'] ?? null;
                $nf = $nfId ? $namedFieldMap->get($nfId) : null;
                if ($nf) {
                    $sourceColumn = $nf->source_column ?? 'unknown';
                    $dataField = ($nf->source_type ?? 'manual') . '.' . $sourceColumn;
                    $fieldName = $this->columnToBladeVar($sourceColumn);
                    if (empty($label)) $label = $nf->name ?? '';
                }
            } elseif ($mappingType === 'field_group') {
                $fgId = $mapping['fieldGroupId'] ?? null;
                $fg = $fgId ? $fieldGroupMap->get($fgId) : null;
                $groupSlug = $fg ? Str::slug($fg->name, '_') : 'unknown';
                $dataField = 'group.' . $groupSlug;
                $fieldName = 'group_' . $groupSlug;
                if (empty($label)) $label = $fg->name ?? '';

                // Also emit individual fields within the group for preview
                if ($fg) {
                    foreach (($fg->fields ?? []) as $f) {
                        $nfId = $f['named_field_id'] ?? null;
                        $nf = $nfId ? $namedFieldMap->get($nfId) : null;
                        if (!$nf) continue;

                        $subColumn = $nf->source_column ?? 'unknown';
                        $fields[] = [
                            'id' => 'group_' . $fg->id . '_' . $subColumn,
                            'tag_id' => $tagId,
                            'tag_type' => 'input',
                            'tag_number' => $tagNumber,
                            'mapping_type' => 'field_group_member',
                            'mapping_id' => $nf->id,
                            'named_field_id' => $nf->id,
                            'data_field' => ($nf->source_type ?? 'manual') . '.' . $subColumn,
                            'field_name' => $this->columnToBladeVar($subColumn),
                            'party' => $party,
                            'label' => $f['label_override'] ?? $nf->name ?? '',
                        ];
                    }
                }
            } elseif ($mappingType === 'manual') {
                $manualLabel = $mapping['manualLabel'] ?? $mapping['label'] ?? 'custom';
                $slug = Str::slug($manualLabel, '_');
                $dataField = 'manual.' . $slug;
                $fieldName = 'manual_' . $slug;
                if (empty($label)) $label = $manualLabel;
            }

            // Unmapped input tags: assign unique field_name matching renderInputTag output
            if ($tagType === 'input' && empty($fieldName) && empty($mappingType)) {
                $fieldName = 'manual_field_' . $tagNumber;
                $dataField = 'manual.field_' . $tagNumber;
                if (empty($label)) $label = 'Field ' . $tagNumber;
            }

            $entry = [
                'id' => $tagType . '_' . $tagNumber,
                'tag_id' => $tagId,
                'tag_type' => $tagType,
                'tag_number' => $tagNumber,
                'mapping_type' => $mappingType ?: ($tagType === 'input' ? '' : $tagType),
                'mapping_id' => $mapping['namedFieldId'] ?? $mapping['fieldGroupId'] ?? null,
                'named_field_id' => $mappingType === 'named_field' ? ($nfId ?? null) : null,
                'data_field' => $dataField,
                'field_name' => $fieldName,
                'party' => $party,
                'assignedTo' => $party,
                'label' => $label,
            ];

            if ($tagType === 'signature') {
                $entry['variant'] = $mapping['variant'] ?? 'sig_full';
                $entry['parties'] = $mapping['parties'] ?? [];
            }

            if ($tagType === 'initial') {
                $entry['parties'] = $mapping['parties'] ?? [];
            }

            $fields[] = $entry;
        }

        return $fields;
    }

    /**
     * Detect the character offset where the signature section begins.
     *
     * Scans block elements from the bottom up, marking signature-related elements
     * (party labels, underscores, "signed at", financial summary lines, etc.).
     * Stops when substantive clause content is found.
     *
     * @return int Character offset in $html where signature starts, or -1 if not found.
     */
    private function detectSignatureBoundary(string $html): int
    {
        $wrapped = '<div id="docx-root">' . $html . '</div>';

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('docx-root');
        if (!$root) {
            return -1;
        }

        // Collect direct block-level children
        $blockTags = ['p', 'div', 'table', 'ol', 'ul'];
        $children = [];
        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE
                && in_array(strtolower($child->nodeName), $blockTags)) {
                $children[] = $child;
            }
        }

        if (empty($children)) {
            return -1;
        }

        $partyLabels = [
            'owner', 'tenant', 'agent', 'lessor', 'lessee',
            'date', 'landlord', 'seller', 'buyer', 'witness',
        ];

        $firstSigElement = null;

        for ($i = count($children) - 1; $i >= 0; $i--) {
            $el = $children[$i];
            $text = $el->textContent ?? '';

            $isSigElement = false;

            if (stripos($text, 'signed at') !== false || stripos($text, 'signed by') !== false) {
                $isSigElement = true;
            } elseif (stripos($text, 'signature of') !== false) {
                $isSigElement = true;
            } elseif (stripos($text, 'print name') !== false || stripos($text, 'print naam') !== false) {
                $isSigElement = true;
            } elseif (stripos($text, 'duly authoris') !== false) {
                $isSigElement = true;
            }

            if (!$isSigElement) {
                $stripped = preg_replace('/[\s_\t]+/', '', $text);
                if (empty($stripped)) {
                    $isSigElement = true;
                }
            }

            if (!$isSigElement) {
                $cleanForLabels = preg_replace('/[_\s\t]+/', ' ', $text);
                $cleanForLabels = trim($cleanForLabels);
                if (!empty($cleanForLabels)) {
                    $words = preg_split('/\s+/', $cleanForLabels);
                    $allMatch = true;
                    $matchCount = 0;
                    foreach ($words as $word) {
                        if (in_array(mb_strtolower($word), $partyLabels)) {
                            $matchCount++;
                        } else {
                            $allMatch = false;
                            break;
                        }
                    }
                    if ($allMatch && $matchCount >= 2) {
                        $isSigElement = true;
                    }
                }
            }

            if (!$isSigElement) {
                if (stripos($text, 'Net Amount to Owner') !== false) {
                    $isSigElement = true;
                } elseif (stripos($text, "Let's Assist Fee") !== false || stripos($text, 'Lets Assist Fee') !== false) {
                    $isSigElement = true;
                } elseif (stripos($text, "Agent's Service Fee") !== false || stripos($text, 'Service Fee (Including VAT)') !== false) {
                    $isSigElement = true;
                } elseif (stripos($text, 'Total Rental Amount') !== false && strpos($text, '_') !== false) {
                    $isSigElement = true;
                }
            }

            if (!$isSigElement) {
                $trimmedText = trim($text);
                if (mb_strlen($trimmedText) < 40 && preg_match('/^(\d+\.?\s+)?Signatures?\s*$/i', $trimmedText)) {
                    $isSigElement = true;
                }
            }

            if ($isSigElement) {
                $firstSigElement = $el;
                continue;
            }

            $strippedText = preg_replace('/[\s_]+/', '', $text);
            if (mb_strlen($strippedText) > 60) {
                break;
            }
        }

        if (!$firstSigElement) {
            return -1;
        }

        $elHtml = $dom->saveHTML($firstSigElement);
        $pos = strpos($html, $elHtml);
        if ($pos !== false) {
            return $pos;
        }

        $searchSnippet = substr($elHtml, 0, min(80, strlen($elHtml)));
        $pos = strpos($html, $searchSnippet);
        if ($pos !== false) {
            return $pos;
        }

        return -1;
    }

    /**
     * Convert a source_column to a Blade variable name.
     * Compound columns (e.g. "first_name+last_name") use only the first part.
     */
    private function columnToBladeVar(string $sourceColumn): string
    {
        $column = explode('+', $sourceColumn)[0];
        return Str::camel($column);
    }

    /**
     * Strip any existing blade shell (DOCTYPE, head, style, @includes) from HTML,
     * returning only the document body content. Prevents shell nesting on re-edit cycles.
     */
    private function extractBodyOnly(string $html): string
    {
        // No shell present — return as-is
        if (stripos($html, '<!DOCTYPE') === false) {
            return $html;
        }

        $marker = "@include('docuperfect.web-templates.components.company-header')";
        $lastPos = strrpos($html, $marker);

        if ($lastPos === false) {
            return $html;
        }

        $bodyStart = $lastPos + strlen($marker);

        // Try to find end before closing tags
        $endPatterns = [
            "</div>\n\n</div>\n</body>",
            "</div>\n</div>\n</body>",
            "\n\n</div>\n</body>",
            "\n</div>\n</body>",
        ];

        $end = false;
        foreach ($endPatterns as $pattern) {
            $end = strrpos($html, $pattern, $bodyStart);
            if ($end !== false) break;
        }

        if ($end !== false) {
            return trim(substr($html, $bodyStart, $end - $bodyStart));
        }

        // Fallback — strip closing HTML from end
        $body = trim(substr($html, $bodyStart));
        $body = preg_replace('/<\/div>\s*<\/body>\s*<\/html>\s*$/i', '', $body);
        return trim($body);
    }

    /**
     * Wrap generated HTML body in a full Blade template shell.
     */
    protected function wrapInBladeTemplate(string $bodyHtml, string $templateName): string
    {
        // Note: detectSignatureBoundary() is intentionally NOT called here.
        // The DocxParserService already stripped original sig sections during parse.
        // The signature-block component is appended below via @include.
        $title = e($templateName);

        // If the processed HTML already contains inline sig blocks from SIG tags,
        // don't append the signature-block component (would duplicate signatures)
        $hasInlineSigBlocks = str_contains($bodyHtml, 'class="sig-block"')
            || str_contains($bodyHtml, 'class="sig-preamble"');

        $sigInclude = $hasInlineSigBlocks
            ? ''
            : "\n    @include('docuperfect.web-templates.components.signature-block', ['signing_parties' => \$signing_parties ?? []])";

        return <<<BLADE
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — Home Finders Coastal</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 20mm 15mm 20mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #1a1a1a;
            background: white;
        }

        p {
            margin: 0 0 2pt 0;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 20mm 15mm 20mm;
            background: white;
        }

        @media screen {
            body {
                background: #e5e7eb;
            }
            .page {
                box-shadow: 0 2px 16px rgba(0,0,0,0.15);
                margin-top: 20px;
                margin-bottom: 20px;
            }
        }

        @media print {
            body { background: white; }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }

        /* ---- Field values (inline blanks) ---- */
        .field {
            display: inline;
            border-bottom: 1pt solid #1a1a1a;
            padding: 0 1pt;
            min-width: 80pt;
            font-weight: normal;
            vertical-align: baseline;
            line-height: inherit;
            white-space: nowrap;
        }

        .field:not(:empty) {
            font-weight: bold;
        }

        .field:empty::after {
            content: '\\00a0';
        }

        .field-short {
            min-width: 80pt;
        }

        .field-tiny {
            min-width: 60pt;
        }

        .field-medium {
            min-width: 150pt;
        }

        .field-wide {
            display: block;
            width: 100%;
            min-height: 18pt;
            margin-bottom: 4pt;
        }

        .field-address {
            min-width: 250pt;
        }

        .field-currency::before {
            content: 'R';
            margin-right: 2pt;
        }

        .field-manual {
            background: #fef3c7;
        }

        .field-group {
            display: inline;
        }

        .sig-placeholder, .ini-placeholder {
            display: inline-block;
            padding: 2pt 6pt;
            border: 1px dashed #999;
            color: #666;
            font-size: 9pt;
            font-style: italic;
        }

        .sig-block {
            margin: 12pt 0;
            page-break-inside: avoid;
        }

        .sig-preamble {
            margin-bottom: 8pt;
        }

        .sig-block-party {
            margin-top: 18pt;
            display: inline-block;
            min-width: 200pt;
            vertical-align: top;
            margin-right: 20pt;
        }

        .sig-line {
            font-size: 10pt;
            line-height: 1;
            margin-bottom: 2pt;
        }

        .sig-name {
            font-size: 9pt;
            color: #333;
        }

        strong { font-weight: bold; }
        em { font-style: italic; }
    </style>
</head>
<body>
<div class="page">
    @include('docuperfect.web-templates.components.company-header')

{$bodyHtml}
{$sigInclude}
</div>
</body>
</html>
BLADE;
    }
}
