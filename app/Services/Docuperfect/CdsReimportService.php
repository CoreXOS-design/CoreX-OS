<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateGeneration;
use App\Models\Document;
use App\Models\User;
use App\Support\Docuperfect\CdsDraftSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Re-import from Word — replace the wording of an existing CDS template from a corrected Word file
 * while keeping the template id, its settings and its field bindings.
 * Spec: .ai/specs/cds-reimport-from-word.md.
 *
 * Nothing is written to the template when a file is uploaded. stage() parses the file, carries the
 * old bindings across BY FIELD NAME, stores the .docx privately and opens a builder draft on the
 * template (cdsGenerate then updates the same template id when the person saves). The template's
 * previous content is snapshotted into a superseded generation at that save — never deleted.
 */
class CdsReimportService
{
    public const DISK = 'local';
    public const SOURCE_TYPE = 'template_source';

    /** A label that carries no information about what the field is. */
    private const GENERIC_LABELS = ['field', 'input', 'blank', 'text'];

    public function __construct(private readonly CdsParserService $parser)
    {
    }

    // ───────────────────────────── field list + matching ─────────────────────────────

    /**
     * The input fields of a CDS in the SAME order the builder gives them a parserIndex
     * (TemplateController::extractFieldsFromCds): content items first, then label/value pairs.
     *
     * @return list<array{index:int,name:string,label:string,key:?string}>
     */
    public function fieldList(array $cds): array
    {
        $fields = [];
        $add = function (string $name, string $label) use (&$fields): void {
            $fields[] = [
                'index' => count($fields),
                'name' => $name,
                'label' => $label,
                'key' => self::matchKey($name, $label),
            ];
        };

        foreach ($cds['sections'] ?? [] as $section) {
            foreach ($section['content'] ?? [] as $item) {
                $type = $item['type'] ?? '';
                if ($type === 'field_placeholder') {
                    $add((string) ($item['field_name'] ?? ''), (string) ($item['label'] ?? ''));
                } elseif ($type === 'insertable_block_placeholder' && ($item['purpose'] ?? '') === 'custom_named') {
                    $add((string) ($item['block_id'] ?? ''), (string) ($item['custom_label'] ?? $item['raw_token'] ?? ''));
                }
            }
            foreach ($section['pairs'] ?? [] as $pair) {
                foreach ($pair['fields'] ?? [] as $item) {
                    if (($item['type'] ?? '') === 'field_placeholder') {
                        $add((string) ($item['field_name'] ?? ''), (string) ($item['label'] ?? $pair['label'] ?? ''));
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * The name a field is matched on: its marker / identified name, else its label. Null when the
     * field has no name at all (a bare @@@@ the importer could not identify) — such a field is
     * never matched by position, it is reported.
     */
    public static function matchKey(string $fieldName, string $label): ?string
    {
        foreach ([$fieldName, $label] as $candidate) {
            $key = trim((string) preg_replace('/[^a-z0-9.]+/', '_', strtolower(trim($candidate))), '_');
            if ($key !== '' && ! in_array($key, self::GENERIC_LABELS, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Carry the old template's bindings onto the new Word file's fields by name.
     *
     * Rules (the point of the feature — a shifted paragraph must never shift a binding):
     *  - fields match on name only, never on position;
     *  - a name used once on both sides matches;
     *  - a name used several times matches only when it is used the same number of times on both
     *    sides, OR every old use had the identical binding (then any pairing is the same answer);
     *    otherwise the whole group is reported as unmatched with the reason;
     *  - a field with no name on either side is unmatched.
     *
     * @param  array<int,array<string,mixed>>        $oldTags      editor_state.tags  (each has id + parserIndex)
     * @param  array<string,array<string,mixed>>     $oldMappings  editor_state.mappings keyed by tag id
     * @return array{bindings: array<int,array<string,mixed>>, report: array<string,mixed>}
     */
    public function carryOver(array $oldCds, array $oldTags, array $oldMappings, array $newCds): array
    {
        $old = $this->fieldList($oldCds);
        $new = $this->fieldList($newCds);

        $tagIdByIndex = [];
        foreach ($oldTags as $tag) {
            if (($tag['type'] ?? 'input') === 'input' && isset($tag['parserIndex'], $tag['id'])) {
                $tagIdByIndex[(int) $tag['parserIndex']] = (string) $tag['id'];
            }
        }
        $bindingOf = function (int $oldIndex) use ($tagIdByIndex, $oldMappings): ?array {
            $tagId = $tagIdByIndex[$oldIndex] ?? null;
            $m = $tagId !== null ? ($oldMappings[$tagId] ?? null) : null;

            return is_array($m) && $this->isBound($m) ? $this->bindingShape($m) : null;
        };

        $oldGroups = $this->groupByKey($old);
        $newGroups = $this->groupByKey($new);

        $bindings = [];
        $matched = [];
        $matchedOld = [];
        $matchedNew = [];
        $reasonOld = [];
        $reasonNew = [];

        foreach ($oldGroups as $key => $oldList) {
            $newList = $newGroups[$key] ?? [];
            if ($newList === []) {
                continue; // dropped — reported below
            }
            $pairs = null;
            if (count($oldList) === count($newList)) {
                $pairs = array_map(null, $oldList, $newList);
            } else {
                $sigs = array_unique(array_map(fn ($f) => json_encode($bindingOf($f['index'])), $oldList));
                if (count($sigs) === 1) {
                    // Same binding for every old use — pair what can be paired, the rest take the same binding.
                    $pairs = [];
                    foreach ($newList as $i => $nf) {
                        $pairs[] = [$oldList[min($i, count($oldList) - 1)], $nf];
                    }
                    foreach (array_slice($oldList, count($newList)) as $f) {
                        $reasonOld[$f['index']] = 'The name "' . $key . '" is used fewer times in the new file.';
                    }
                } else {
                    $why = 'The name "' . $key . '" is used ' . count($oldList) . ' time(s) in the old template and '
                        . count($newList) . ' time(s) in the new file with different settings, so it cannot be matched safely.';
                    foreach ($oldList as $f) {
                        $reasonOld[$f['index']] = $why;
                    }
                    foreach ($newList as $f) {
                        $reasonNew[$f['index']] = $why;
                    }
                    continue;
                }
            }
            foreach ($pairs as [$of, $nf]) {
                $b = $bindingOf($of['index']);
                if ($b !== null) {
                    $bindings[$nf['index']] = $b;
                }
                $matched[] = ['name' => $key, 'old_index' => $of['index'], 'new_index' => $nf['index'], 'bound' => $b !== null];
                $matchedOld[$of['index']] = true;
                $matchedNew[$nf['index']] = true;
            }
        }

        $dropped = [];
        foreach ($old as $f) {
            if (isset($matchedOld[$f['index']])) {
                continue;
            }
            $b = $bindingOf($f['index']);
            $dropped[] = [
                'name' => $f['key'] ?? ($f['label'] ?: $f['name']),
                'label' => $f['label'] !== '' ? $f['label'] : $f['name'],
                'old_index' => $f['index'],
                'was_bound_to' => $b['label'] ?? null,
                'reason' => $reasonOld[$f['index']] ?? ($f['key'] === null ? 'The old field had no name to match on.' : 'No field with this name in the new file.'),
            ];
        }
        $added = [];
        foreach ($new as $f) {
            if (isset($matchedNew[$f['index']])) {
                continue;
            }
            $added[] = [
                'name' => $f['key'] ?? ($f['label'] ?: $f['name']),
                'label' => $f['label'] !== '' ? $f['label'] : ($f['name'] !== '' ? $f['name'] : 'Unnamed field'),
                'new_index' => $f['index'],
                'reason' => $reasonNew[$f['index']] ?? ($f['key'] === null ? 'This field has no name in the Word file, so it could not be matched.' : 'A new field — nothing in the old template had this name.'),
            ];
        }

        return [
            'bindings' => $bindings,
            'report' => [
                'old_count' => count($old),
                'new_count' => count($new),
                'matched_count' => count($matched),
                'carried_count' => count($bindings),
                'matched' => $matched,
                'dropped' => $dropped,
                'added' => $added,
            ],
        ];
    }

    // ───────────────────────────── stage / snapshot / apply ─────────────────────────────

    /**
     * Parse the upload, store it privately, open a builder draft on the template. Writes nothing to
     * the template itself.
     *
     * @return array{generation: TemplateGeneration, draft: CdsDraft}
     */
    public function stage(Template $template, UploadedFile $file, User $actor): array
    {
        if ($template->template_type !== 'cds') {
            throw new \InvalidArgumentException('Only a template built from a Word file can be re-imported.');
        }

        $cds = $this->parser->parse($file->getPathname());
        if (empty($cds['sections'])) {
            throw new \RuntimeException('That file did not contain any readable text. Check it is a Word .docx and try again.');
        }

        $editor = is_array($template->editor_state) ? $template->editor_state : [];
        $carry = $this->carryOver(
            is_array($template->cds_json) ? $template->cds_json : [],
            (array) ($editor['tags'] ?? []),
            (array) ($editor['mappings'] ?? []),
            $cds,
        );

        $agencyId = $template->agency_id ? (int) $template->agency_id : null;
        $sha = (string) hash_file('sha256', $file->getPathname());
        $name = Document::sanitizeOriginalName($file->getClientOriginalName()) ?: 'template.docx';

        return DB::transaction(function () use ($template, $file, $actor, $cds, $carry, $agencyId, $sha, $name) {
            $path = Storage::disk(self::DISK)->putFileAs(
                'template-sources/' . ($agencyId ?? 0) . '/' . $template->id,
                $file,
                now()->format('Ymd-His') . '-' . substr($sha, 0, 12) . '.docx'
            );

            $document = Document::withoutAgencyStamping(fn () => Document::create([
                'agency_id' => $agencyId,
                'original_name' => $name,
                'storage_path' => $path,
                'disk' => self::DISK,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'size' => (int) $file->getSize(),
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $template->id,
                'uploaded_by' => $actor->id,
            ]));

            // A newer upload overtakes any earlier one that was never saved.
            TemplateGeneration::query()
                ->where('template_id', $template->id)->where('status', TemplateGeneration::PENDING)
                ->update(['status' => TemplateGeneration::ABANDONED]);
            CdsDraft::withoutGlobalScopes()
                ->where('source_template_id', $template->id)->where('user_id', $actor->id)->where('status', 'draft')
                ->update(['status' => 'abandoned']);

            $draft = CdsDraft::withoutGlobalScopes()->create([
                'user_id' => $actor->id,
                'agency_id' => $agencyId,
                'template_name' => $template->name,
                'cds_json' => $cds,
                'tags' => null,
                'mappings' => null,
                'tagged_html' => null,
                'settings' => CdsDraftSettings::fromTemplate($template),
                'source_template_id' => $template->id,
                'status' => 'draft',
            ]);

            $generation = TemplateGeneration::create([
                'agency_id' => $agencyId,
                'template_id' => $template->id,
                'generation' => 0,
                'status' => TemplateGeneration::PENDING,
                'draft_id' => $draft->id,
                'document_id' => $document->id,
                'original_filename' => $name,
                'file_sha256' => $sha,
                'uploaded_by' => $actor->id,
                // The parser's own "what did not come across" line rides in the report, so the match report says it first.
                'report' => $carry['report'] + ['parse_line' => $cds['parse_report']['line'] ?? null],
                'carried_bindings' => $carry['bindings'],
            ]);

            return ['generation' => $generation, 'draft' => $draft];
        });
    }

    /** What the template holds right now — taken BEFORE the save overwrites it. */
    public function snapshot(Template $template): array
    {
        return [
            'cds_json' => $template->cds_json,
            'editor_state' => $template->editor_state,
            'field_mappings' => $template->field_mappings,
        ];
    }

    /**
     * The saved draft's pending generation becomes current; whatever was current (or, for a template
     * that never had a file kept, the content it had before) is kept as a superseded generation.
     */
    public function apply(CdsDraft $draft, array $snapshot, User $actor): ?TemplateGeneration
    {
        $pending = TemplateGeneration::query()
            ->where('draft_id', $draft->id)->where('status', TemplateGeneration::PENDING)->first();
        if (! $pending) {
            return null;
        }

        return DB::transaction(function () use ($pending, $snapshot, $actor) {
            $templateId = (int) $pending->template_id;
            $next = (int) TemplateGeneration::withTrashed()->where('template_id', $templateId)->max('generation') + 1;

            $current = TemplateGeneration::query()
                ->where('template_id', $templateId)->where('status', TemplateGeneration::CURRENT)->first();

            $keep = $snapshot + ['status' => TemplateGeneration::SUPERSEDED, 'superseded_at' => now(), 'superseded_by' => $actor->id];
            if ($current) {
                $current->update($keep);
            } else {
                TemplateGeneration::create($keep + [
                    'agency_id' => $pending->agency_id,
                    'template_id' => $templateId,
                    'generation' => $next++,
                    'document_id' => null,
                    'original_filename' => null,
                ]);
            }

            $pending->update(['status' => TemplateGeneration::CURRENT, 'generation' => $next, 'applied_at' => now()]);

            return $pending->fresh();
        });
    }

    /** The pending generation for a builder draft, if the draft came from a re-import. */
    public function pendingFor(CdsDraft $draft): ?TemplateGeneration
    {
        return TemplateGeneration::query()
            ->where('draft_id', $draft->id)->where('status', TemplateGeneration::PENDING)->first();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** @return array<string,list<array>> */
    private function groupByKey(array $fields): array
    {
        $groups = [];
        foreach ($fields as $f) {
            if ($f['key'] !== null) {
                $groups[$f['key']][] = $f;
            }
        }

        return $groups;
    }

    private function isBound(array $mapping): bool
    {
        return ($mapping['typeKey'] ?? '') !== ''
            || ($mapping['namedFieldId'] ?? null) !== null
            || ($mapping['fieldGroupId'] ?? null) !== null
            || (($mapping['mappingType'] ?? '') === 'manual' && trim((string) ($mapping['manualLabel'] ?? $mapping['label'] ?? '')) !== '');
    }

    /** The keys the builder's _mappingFromServerBinding() reads. */
    private function bindingShape(array $m): array
    {
        return [
            'mappingType' => $m['mappingType'] ?? 'named_field',
            'typeKey' => $m['typeKey'] ?? '',
            'namedFieldId' => $m['namedFieldId'] ?? null,
            'fieldGroupId' => $m['fieldGroupId'] ?? null,
            'label' => $m['label'] ?? '',
            'manualLabel' => $m['manualLabel'] ?? '',
            'party' => $m['party'] ?? 'auto',
            'partyLocked' => (bool) ($m['partyLocked'] ?? false),
            'sourceType' => $m['sourceType'] ?? '',
            'sourceContactType' => $m['sourceContactType'] ?? '',
            'editable_by' => is_array($m['editable_by'] ?? null) ? array_values($m['editable_by']) : [],
            'confidence' => 'high',
        ];
    }
}
