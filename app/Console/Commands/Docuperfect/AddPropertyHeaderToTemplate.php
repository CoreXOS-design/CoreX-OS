<?php

declare(strict_types=1);

namespace App\Console\Commands\Docuperfect;

use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use Illuminate\Console\Command;

/**
 * HOTFIX 2026-10-21 — give a stand-alone Mandatory Disclosure (CDS import) the property header block the sales
 * Mandatory Disclosure carries ("This condition report concerns the immovable property situated at <address>").
 *
 * The block is a BOUND Property Address tag (named field `property` / `address+suburb`), exactly as the CDS builder
 * stores one — never typed text — so it resolves from the property on every route and shows in Fill & Review. The
 * template's generated blade is then rebuilt from its stored HTML (the same generation the builder's save runs).
 *
 * A template is data, and its id differs per environment, so this is a command rather than a migration:
 * dry-run by default, --apply writes, idempotent (a template that already carries the block is left alone).
 * Refuses anything that is not a CDS Mandatory Disclosure.
 */
final class AddPropertyHeaderToTemplate extends Command
{
    protected $signature = 'esign:add-property-header
        {template : Template id of the stand-alone Mandatory Disclosure}
        {--apply : Write the change (default is a dry-run report)}';

    protected $description = 'Add the bound property-address header block to a CDS Mandatory Disclosure template.';

    private const MARKER = 'data-corex-property-header';

    public function handle(): int
    {
        $template = Template::withoutGlobalScopes()->find((int) $this->argument('template'));
        if (! $template) {
            $this->error('No such template.');
            return self::FAILURE;
        }
        if ($template->template_type !== 'cds' || stripos((string) $template->name, 'disclosure') === false) {
            $this->error("Template #{$template->id} \"{$template->name}\" is not a CDS Mandatory Disclosure — refusing.");
            return self::FAILURE;
        }

        $editor = is_array($template->editor_state) ? $template->editor_state : (json_decode((string) $template->editor_state, true) ?: []);
        $tagged = (string) ($editor['tagged_html'] ?? '');
        if (trim($tagged) === '') {
            $this->error('The template has no stored editor HTML to add the block to.');
            return self::FAILURE;
        }
        if (str_contains($tagged, self::MARKER)) {
            $this->info("Template #{$template->id} already carries the property header — nothing to do.");
            return self::SUCCESS;
        }

        $named = NamedField::where('source_type', 'property')->where('source_column', 'address+suburb')->orderBy('id')->first();
        if (! $named) {
            $this->error('The Property Address named field (property / address+suburb) does not exist on this environment.');
            return self::FAILURE;
        }

        $tagId = 'tag-ph-' . substr(md5($template->id . '|property-header'), 0, 12);
        $span = '<span class="doc-tag doc-tag-input-linked" data-field-name="property.address+suburb" data-field-type="text"'
            . ' data-field-label="Property Address" data-confidence="high"'
            . ' style="border-color: rgb(34, 197, 94) rgb(34, 197, 94) rgb(168, 85, 247); border-bottom-width: 2px; border-bottom-style: solid;"'
            . ' data-tag-id="' . $tagId . '" contenteditable="false" title="Editable by: owner_party, agent"'
            . ' data-field="property.address+suburb">[Property Address]</span>';
        $block = '<div class="corex-clause" ' . self::MARKER . '="1"><span class="corex-clause-text">'
            . 'This condition report concerns the immovable property situated at ' . $span
            . ' (the &ldquo;Property&rdquo;).</span></div>';

        // Straight after the document title; a template without one gets the block first.
        $newTagged = preg_match('#^(\s*<div class="corex-h1">.*?</div>)#s', $tagged)
            ? preg_replace('#^(\s*<div class="corex-h1">.*?</div>)#s', '$1' . "\n" . str_replace('$', '\\$', $block), $tagged, 1)
            : $block . "\n" . $tagged;

        $mapping = [
            'label' => 'Property Address', 'party' => 'auto', 'sigType' => 'electronic', 'typeKey' => 'sf:property',
            'confidence' => 'high', 'sourceType' => 'property', 'editable_by' => ['owner_party', 'agent'],
            'manualLabel' => null, 'mappingType' => 'named_field', 'partyLocked' => false, 'fieldGroupId' => null,
            'namedFieldId' => (int) $named->id, 'sourceContactType' => null,
        ];
        $tags = is_array($editor['tags'] ?? null) ? $editor['tags'] : [];
        $tags[] = ['id' => $tagId, 'type' => 'input', 'label' => '[INPUT ' . (count($tags) + 1) . ']', 'number' => count($tags) + 1];
        $editorMappings = is_array($editor['mappings'] ?? null) ? $editor['mappings'] : [];
        $editorMappings[$tagId] = $mapping;
        $fieldMappings = is_array($template->field_mappings) ? $template->field_mappings : (json_decode((string) $template->field_mappings, true) ?: []);
        $fieldMappings[$tagId] = $mapping;

        $this->line("Template #{$template->id} \"{$template->name}\": add the bound Property Address block after the title (named field #{$named->id}).");
        if (! $this->option('apply')) {
            $this->warn('Dry run — re-run with --apply to write.');
            return self::SUCCESS;
        }

        $editor['tagged_html'] = $newTagged;
        $editor['tags'] = $tags;
        $editor['mappings'] = $editorMappings;
        $template->update(['editor_state' => $editor, 'field_mappings' => $fieldMappings]);
        $blade = app(WebTemplateBladeEnsurer::class)->regenerate($template->fresh());
        $this->info("Done — blade rebuilt: {$blade}");

        return self::SUCCESS;
    }
}
