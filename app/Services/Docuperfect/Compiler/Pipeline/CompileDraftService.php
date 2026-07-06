<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Pipeline;

use App\Models\Docuperfect\CompiledTemplate;
use App\Services\Docuperfect\Compiler\Contracts\CompileDraftManager;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;
use RuntimeException;

/**
 * AT-177 / WS4-E — manages the mutable COMPILE DRAFT (spec §3 steps 3–4). The draft is a
 * `CompiledTemplate` in status=draft whose `structure` is the working CDS; the Studio (WS4-S)
 * drives binding + topology through these methods. Every mutation guards that the row is still
 * a draft — a published row is immutable (WS0), so editing is always a new version.
 */
final class CompileDraftService implements CompileDraftManager
{
    public function createFromSegmentation(SegmentationResult $segmentation, array $attributes = []): CompiledTemplate
    {
        $structure = $segmentation->structure;

        return CompiledTemplate::create(array_merge([
            'agency_id' => null,
            'family' => (string) ($structure['family'] ?? 'untitled'),
            'legal_class' => (string) ($structure['legal_class'] ?? 'general'),
            'delivery_modes' => $structure['delivery_modes'] ?? ['web_esign', 'pdf_wetink', 'download'],
            'data_dictionary_version' => (int) ($structure['data_dictionary_version'] ?? 1),
            'structure' => $structure,
            'status' => CompiledTemplate::STATUS_DRAFT,
            'lint_status' => CompiledTemplate::LINT_PENDING,
        ], $attributes));
    }

    public function updateStructure(CompiledTemplate $draft, array $structure): CompiledTemplate
    {
        $this->assertDraft($draft);
        $draft->structure = $structure;
        // Editing invalidates any prior lint pass.
        $draft->lint_status = CompiledTemplate::LINT_PENDING;
        $draft->save();

        return $draft;
    }

    public function bindField(CompiledTemplate $draft, string $blockId, string $fieldId, string $dictionaryKey): CompiledTemplate
    {
        return $this->mutate($draft, function (array &$structure) use ($blockId, $fieldId, $dictionaryKey): void {
            $block = &$this->findBlock($structure, $blockId);
            $found = false;
            // Iterate $block['fields'] DIRECTLY — `?? []` would copy and lose the mutation.
            if (isset($block['fields']) && is_array($block['fields'])) {
                foreach ($block['fields'] as &$field) {
                    if (($field['field_id'] ?? null) === $fieldId) {
                        $field['binding'] = $dictionaryKey;
                        $found = true;
                        break;
                    }
                }
                unset($field);
            }
            unset($block);
            if (! $found) {
                throw new RuntimeException("Field [{$fieldId}] not found in block [{$blockId}].");
            }
        });
    }

    public function declareParty(CompiledTemplate $draft, array $party): CompiledTemplate
    {
        return $this->mutate($draft, function (array &$structure) use ($party): void {
            $key = (string) ($party['key'] ?? '');
            if ($key === '') {
                throw new RuntimeException('A party must have a key.');
            }
            $structure['parties'] ??= [];
            foreach ($structure['parties'] as $i => $existing) {
                if (($existing['key'] ?? null) === $key) {
                    $structure['parties'][$i] = array_merge($existing, $party);

                    return;
                }
            }
            $structure['parties'][] = $party;
        });
    }

    public function setBlockVisibility(CompiledTemplate $draft, string $blockId, array $expr): CompiledTemplate
    {
        return $this->mutate($draft, function (array &$structure) use ($blockId, $expr): void {
            $block = &$this->findBlock($structure, $blockId);
            $block['visibility'] = $expr;
        });
    }

    public function setBlockEditability(CompiledTemplate $draft, string $blockId, array $expr): CompiledTemplate
    {
        return $this->mutate($draft, function (array &$structure) use ($blockId, $expr): void {
            $block = &$this->findBlock($structure, $blockId);
            $block['editability'] = $expr;
        });
    }

    // ── internals ───────────────────────────────────────────────────────────────

    private function mutate(CompiledTemplate $draft, callable $fn): CompiledTemplate
    {
        $this->assertDraft($draft);
        $structure = $draft->structure ?? [];
        $fn($structure);

        return $this->updateStructure($draft, $structure);
    }

    /** @return array<string,mixed> by reference */
    private function &findBlock(array &$structure, string $blockId): array
    {
        // NB: iterate $structure['blocks'] DIRECTLY — `?? []` would copy the array and the
        // by-reference mutation would be lost.
        if (! isset($structure['blocks']) || ! is_array($structure['blocks'])) {
            throw new RuntimeException("The draft has no blocks.");
        }
        foreach ($structure['blocks'] as $i => &$block) {
            if (($block['block_id'] ?? null) === $blockId) {
                return $block;
            }
        }
        unset($block);

        throw new RuntimeException("Block [{$blockId}] not found in the draft.");
    }

    private function assertDraft(CompiledTemplate $draft): void
    {
        if ($draft->status === CompiledTemplate::STATUS_PUBLISHED) {
            throw new RuntimeException(
                "CompiledTemplate #{$draft->id} is published and immutable; edit a new draft version instead (AT-177 §5)."
            );
        }
    }
}
