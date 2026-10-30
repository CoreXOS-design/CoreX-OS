<?php

namespace App\Services\RentalApplications;

use App\Models\RentalApplication;
use App\Models\RentalApplicationCustomField;
use App\Models\RentalApplicationFieldChange;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AT-516 - an agent corrects what the applicant typed, after the application has come back.
 *
 * Evidence rule: the applicant's signed answers are sealed, append-only, in rental_application_generations (written by
 * RentalApplicationGeneration::seal() at every submit). This service never touches that table - it writes the CURRENT values on
 * the rental_applications row (what approval / contact / lease read from) and one rental_application_field_changes row per field
 * that really changed (old -> new, who, when, why). The signed PDF is not regenerated.
 *
 * A "field" is addressed by a path: a plain column key ("id_number"), a cell inside a repeating block ("co_occupants.0.name",
 * "affordability.income.2.amount", "co_applicant.full_name"), or a custom answer ("custom_field_values.<key>"). Existing block
 * rows can be corrected in place; rows are not added or removed here (that is what sending the application back is for).
 */
class RentalApplicationDetailEditor
{
    /** Statuses in which the agent still owns the application and the applicant's answers are settled. */
    public const EDITABLE_STATUSES = ['returned', 'under_assessment'];

    /** @var array<int, array<string, array>> */
    private array $definitionCache = [];

    private const CUSTOM_PREFIX = 'custom_field_values.';

    private const SELECT_LABELS = [
        'employment_type' => [
            'permanently_employed' => 'Permanently employed',
            'business_owner_personal_account' => 'Business owner (personal account)',
            'business_owner_business_account' => 'Business owner (business account)',
        ],
        'judgments' => ['yes' => 'Yes', 'no' => 'No'],
        'rental_term_months' => ['6' => '6 months', '12' => '12 months', '24' => '24 months'],
    ];

    public function canEdit(RentalApplication $application): bool
    {
        return $this->lockReason($application) === null;
    }

    /** Why the agent cannot correct details right now, in plain words; null when they can. */
    public function lockReason(RentalApplication $application): ?string
    {
        if ($application->isPendingAuthorisation()) {
            return 'This application is with the authoriser for a decision - the details are read-only until it comes back to you.';
        }
        if (! in_array($application->status, self::EDITABLE_STATUSES, true)) {
            return 'Details can only be corrected once the application has come back from the applicant and before it is decided.';
        }

        return null;
    }

    /**
     * Every editable field, in screen order, ready for the form: path, label, section, input type, choices and the current value
     * as the input shows it.
     *
     * @return array<int, array{path: string, label: string, section: string, type: string, options: ?array, step: ?string, value: ?string}>
     */
    public function fields(RentalApplication $application): array
    {
        $fields = array_values($this->definitions($application));
        $values = $this->currentValues($application);
        foreach ($fields as &$field) {
            $field['value'] = $values[$field['path']] ?? null;
        }
        unset($field);

        return $fields;
    }

    /** Plain-words value for a log line / email / panel (yes/no, dates, money, choice labels). */
    public function displayValue(RentalApplication $application, string $path, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '(blank)';
        }
        $def = $this->definitions($application)[$path] ?? null;
        $type = $def['type'] ?? 'text';

        return match (true) {
            $type === 'money' => 'R ' . number_format((float) $value, 2),
            $type === 'bool' => $value === '1' ? 'Yes' : 'No',
            $type === 'date' => rescue(fn () => Carbon::parse($value)->format('j F Y'), $value, false),
            isset($def['options'][$value]) => $def['options'][$value],
            default => $value,
        };
    }

    /**
     * Apply the agent's corrections. $input is path => new value for the fields the form posted.
     *
     * @return array<int, RentalApplicationFieldChange> the rows written (empty when nothing really changed)
     * @throws ValidationException when a new value is not acceptable (nothing is saved)
     * @throws \DomainException when the application is locked
     */
    public function apply(RentalApplication $application, array $input, ?User $user, ?string $note = null): array
    {
        if ($reason = $this->lockReason($application)) {
            throw new \DomainException($reason);
        }

        $definitions = $this->definitions($application);
        $current = $this->currentValues($application);

        $unknown = array_diff(array_keys($input), array_keys($definitions));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'One of the fields posted is not a field on this application.']);
        }

        $submitted = [];
        foreach ($input as $path => $value) {
            $submitted[(string) $path] = $this->normalise($definitions[$path], is_scalar($value) ? (string) $value : null);
        }

        $rules = RentalApplication::fieldValidationRules();
        $scalarChanges = [];
        $blockInput = [];
        $customInput = [];
        foreach ($submitted as $path => $value) {
            if (str_starts_with($path, self::CUSTOM_PREFIX)) {
                $customInput[substr($path, strlen(self::CUSTOM_PREFIX))] = $value;
            } elseif (str_contains($path, '.')) {
                $blockInput[explode('.', $path, 2)[0]][$path] = $value;
            } elseif (($current[$path] ?? null) !== $value) {
                $scalarChanges[$path] = $value;
            }
        }

        // Scalars: judged by the SAME rules the applicant's form uses. The cross-field rules (a moved-out date after the
        // moved-in date) need the other values, so the validator sees the current row with the changes laid over it.
        if ($scalarChanges !== []) {
            $data = [];
            foreach ($current as $path => $value) {
                if (! str_contains($path, '.')) {
                    $data[$path] = $value;
                }
            }
            $validator = Validator::make(
                array_merge($data, $scalarChanges),
                array_intersect_key($rules, $scalarChanges),
                [],
                collect($scalarChanges)->mapWithKeys(fn ($v, $path) => [$path => $definitions[$path]['label']])->all(),
            );
            $validator->validate();
        }

        // Blocks: rebuild the whole block from its current cells with the posted cells laid over, clean it exactly as the
        // applicant's own save does, then compare cell by cell.
        $newBlocks = [];
        foreach ($blockInput as $block => $cells) {
            $rebuilt = $application->getAttribute($block) ?? [];
            foreach ($cells as $path => $value) {
                $this->setCell($rebuilt, array_slice(explode('.', $path), 1), $value);
            }
            $cleaned = RentalApplication::normaliseBlocks([$block => $rebuilt])[$block];
            $newBlocks[$block] = $cleaned;
        }

        $customErrors = [];
        $newCustom = $application->custom_field_values ?? [];
        foreach ($customInput as $key => $value) {
            if (($current[self::CUSTOM_PREFIX . $key] ?? null) === $value) {
                continue;
            }
            if ($value !== null && mb_strlen($value) > 2000) {
                $customErrors[self::CUSTOM_PREFIX . $key] = $definitions[self::CUSTOM_PREFIX . $key]['label'] . ' is too long.';
                continue;
            }
            $newCustom[$key] = $value;
        }
        if ($customErrors !== []) {
            throw ValidationException::withMessages($customErrors);
        }

        // Work out what really changed, path by path, against the values before this save.
        $changes = [];
        foreach ($scalarChanges as $path => $value) {
            $changes[$path] = $value;
        }
        foreach ($newBlocks as $block => $cleaned) {
            $after = $this->flattenBlock($block, $cleaned);
            $before = $this->flattenBlock($block, $application->getAttribute($block));
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
                if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                    $changes[$path] = $after[$path] ?? null;
                    $current[$path] = $before[$path] ?? null;
                }
            }
        }
        foreach ($customInput as $key => $value) {
            $path = self::CUSTOM_PREFIX . $key;
            if (($current[$path] ?? null) !== $value) {
                $changes[$path] = $value;
            }
        }

        if ($changes === []) {
            return [];
        }

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $batch = (string) Str::uuid();
        $rows = [];

        DB::transaction(function () use ($application, $user, $scalarChanges, $newBlocks, $newCustom, $customInput, $changes, $current, $definitions, $note, $batch, &$rows) {
            foreach ($scalarChanges as $path => $value) {
                $application->setAttribute($path, $value);
            }
            foreach ($newBlocks as $block => $cleaned) {
                $application->setAttribute($block, $cleaned);
            }
            if ($customInput !== []) {
                $application->custom_field_values = $newCustom;
            }
            $application->save();

            $now = now();
            foreach ($changes as $path => $newValue) {
                $label = $definitions[$path]['label'] ?? Str::headline(str_replace(['_', '.'], ' ', $path));
                $rows[] = RentalApplicationFieldChange::create([
                    'rental_application_id' => $application->id,
                    'agency_id' => $application->agency_id,
                    'batch_uuid' => $batch,
                    'generation' => $application->current_generation,
                    'field_key' => $path,
                    'field_label' => $label,
                    'old_value' => $current[$path] ?? null,
                    'new_value' => $newValue,
                    'changed_by_user_id' => $user?->id,
                    'note' => $note,
                    'created_at' => $now,
                ]);
            }

            RentalApplicationStatusHistory::record(
                $application,
                $application->status,
                $application->status,
                $user,
                'Corrected applicant details: ' . collect($rows)->pluck('field_label')->implode(', ') . ($note ? ' - ' . $note : ''),
            );
        });

        unset($this->definitionCache[$application->id]);

        app(RentalApplicationAuditService::class)->log(
            $application,
            eventCategory: 'agent',
            eventType: 'details_corrected',
            user: $user,
            reason: $note,
            oldValues: collect($rows)->mapWithKeys(fn ($r) => [$r->field_key => $r->old_value])->all(),
            newValues: collect($rows)->mapWithKeys(fn ($r) => [$r->field_key => $r->new_value])->all(),
            humanSummary: 'Corrected applicant details: ' . collect($rows)->pluck('field_label')->implode(', '),
        );

        return $rows;
    }

    /**
     * path => definition for every correctable field, in screen order.
     *
     * @return array<string, array{path: string, label: string, section: string, type: string, options: ?array, step: ?string, order: int}>
     */
    private function definitions(RentalApplication $application): array
    {
        // Built once per application per request: the field config and the repeating-block rows are read from the database.
        if (isset($this->definitionCache[$application->id])) {
            return $this->definitionCache[$application->id];
        }
        $config = $application->displayFieldConfig();
        $rules = RentalApplication::fieldValidationRules();
        $defs = [];

        $sectionOrder = array_flip(array_keys(RentalApplication::SUBMISSION_FIELD_SECTIONS));
        $rank = fn (?string $section) => $sectionOrder[$section] ?? 50;

        foreach ($rules as $key => $ruleSet) {
            if (in_array($key, RentalApplication::BLOCK_KEYS, true)) {
                continue;
            }
            $cfg = $config[$key] ?? null;
            $defs[$key] = $this->describe($key, $cfg['label'] ?? Str::headline($key), $cfg['section'] ?? 'Other', $ruleSet)
                + ['order' => $rank($cfg['section'] ?? null) * 1000 + (int) ($cfg['order'] ?? 999)];
        }

        $block = fn (string $section) => $rank($section) * 1000 + 500;
        foreach (RentalApplication::CO_APPLICANT_FIELDS as $key => [$label, $kind]) {
            $path = 'co_applicant.' . $key;
            $defs[$path] = [
                'path' => $path, 'label' => 'Co-applicant - ' . $label, 'section' => 'Co-applicant, household and finances',
                'type' => $kind === 'money' ? 'money' : ($kind === 'regime' ? 'select' : ($kind === 'email' ? 'email' : 'text')),
                'options' => $kind === 'regime' ? array_combine(RentalApplication::MARRIAGE_REGIMES, RentalApplication::MARRIAGE_REGIMES) : null,
                'step' => $kind === 'money' ? '0.01' : null, 'order' => $block('Co-applicant, household and finances'),
            ];
        }
        $rowCells = [
            'co_occupants' => ['Co-occupant', ['name' => 'name', 'relationship' => 'relationship', 'age' => 'age']],
            'pets' => ['Pet', ['type' => 'type', 'breed' => 'breed', 'size' => 'size']],
        ];
        foreach ($rowCells as $blockKey => [$noun, $cells]) {
            foreach ((array) ($application->getAttribute($blockKey) ?? []) as $i => $row) {
                foreach ($cells as $cell => $cellLabel) {
                    $path = "{$blockKey}.{$i}.{$cell}";
                    $defs[$path] = [
                        'path' => $path, 'label' => $noun . ' ' . ($i + 1) . ' - ' . $cellLabel, 'section' => 'Co-applicant, household and finances',
                        'type' => 'text', 'options' => null, 'step' => null, 'order' => $block('Co-applicant, household and finances'),
                    ];
                }
            }
        }
        foreach (['income' => 'Income', 'expenses' => 'Expense'] as $group => $noun) {
            foreach ((array) (($application->affordability ?? [])[$group] ?? []) as $i => $line) {
                foreach (['label' => 'text', 'amount' => 'money'] as $cell => $type) {
                    $path = "affordability.{$group}.{$i}.{$cell}";
                    $defs[$path] = [
                        'path' => $path, 'label' => $noun . ' line ' . ($i + 1) . ' - ' . $cell, 'section' => 'Co-applicant, household and finances',
                        'type' => $type, 'options' => null, 'step' => $type === 'money' ? '0.01' : null, 'order' => $block('Co-applicant, household and finances'),
                    ];
                }
            }
        }

        foreach ($config as $key => $cfg) {
            if (! ($cfg['is_custom'] ?? false) || ($cfg['field_type'] ?? null) === RentalApplicationCustomField::TYPE_FILE) {
                continue;
            }
            $type = match ($cfg['field_type']) {
                RentalApplicationCustomField::TYPE_NUMBER => 'number',
                RentalApplicationCustomField::TYPE_DATE => 'date',
                RentalApplicationCustomField::TYPE_YES_NO => 'bool',
                RentalApplicationCustomField::TYPE_CHOICE_LIST => 'select',
                default => 'text',
            };
            $options = $type === 'select' ? array_combine((array) $cfg['options'], (array) $cfg['options']) : null;
            $defs[self::CUSTOM_PREFIX . $key] = [
                'path' => self::CUSTOM_PREFIX . $key, 'label' => $cfg['label'], 'section' => 'Additional Questions',
                'type' => $type, 'options' => $options ?: null, 'step' => $type === 'number' ? 'any' : null, 'order' => 90000 + (int) $cfg['order'],
            ];
        }

        uasort($defs, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $this->definitionCache[$application->id] = $defs;
    }

    /** @param array<int, mixed> $ruleSet */
    private function describe(string $key, string $label, string $section, array $ruleSet): array
    {
        $flat = array_filter($ruleSet, 'is_string');
        $has = fn (string $rule) => in_array($rule, $flat, true);
        $max = 0;
        $in = null;
        foreach ($flat as $rule) {
            if (str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }
            if (str_starts_with($rule, 'in:')) {
                $in = explode(',', substr($rule, 3));
            }
        }

        $options = null;
        if ($in !== null) {
            $labels = self::SELECT_LABELS[$key] ?? ($key === 'current_living_situation' ? RentalApplication::CURRENT_LIVING_SITUATION_LABELS : []);
            $options = [];
            foreach ($in as $value) {
                $options[$value] = $labels[$value] ?? Str::headline($value);
            }
        }

        $type = match (true) {
            $options !== null => 'select',
            $has('boolean') => 'bool',
            $has('date') => 'date',
            $has('integer') => 'number',
            in_array($key, ['monthly_salary', 'current_rental_amount'], true) => 'money',
            $has('numeric') => 'number',
            $has('email') => 'email',
            $max >= 400 => 'textarea',
            default => 'text',
        };

        return [
            'path' => $key, 'label' => $label, 'section' => $section, 'type' => $type, 'options' => $options,
            'step' => match ($type) { 'money' => '0.01', 'number' => $has('integer') ? '1' : 'any', default => null },
        ];
    }

    /** path => the value as the form shows it (normalised string), for every correctable path. */
    private function currentValues(RentalApplication $application): array
    {
        $defs = $this->definitions($application);
        $values = [];
        foreach ($defs as $path => $def) {
            if (str_starts_with($path, self::CUSTOM_PREFIX)) {
                $values[$path] = $this->normalise($def, ($application->custom_field_values ?? [])[substr($path, strlen(self::CUSTOM_PREFIX))] ?? null);
            } elseif (str_contains($path, '.')) {
                $values[$path] = $this->normalise($def, $this->cell($application, $path));
            } else {
                $values[$path] = $this->normalise($def, $application->getAttribute($path));
            }
        }

        return $values;
    }

    private function cell(RentalApplication $application, string $path): mixed
    {
        $parts = explode('.', $path);
        $value = $application->getAttribute(array_shift($parts));
        foreach ($parts as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return is_scalar($value) ? $value : null;
    }

    /** One canonical string per value, so "12000.00" and "12000" are the same answer and "" is "no answer". */
    private function normalise(array $def, mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (in_array($def['type'] ?? 'text', ['money', 'number'], true) && is_numeric($value)) {
            $value = (string) (float) $value;
        }
        if (($def['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            $value = substr($value, 0, 10);
        }

        return $value;
    }

    private function setCell(array &$tree, array $parts, ?string $value): void
    {
        $head = array_shift($parts);
        if ($parts === []) {
            $tree[$head] = $value;

            return;
        }
        if (! isset($tree[$head]) || ! is_array($tree[$head])) {
            $tree[$head] = [];
        }
        $this->setCell($tree[$head], $parts, $value);
    }

    /** @return array<string, ?string> path => normalised string for every cell of a block */
    private function flattenBlock(string $block, mixed $value): array
    {
        $out = [];
        $walk = function ($node, string $prefix) use (&$walk, &$out) {
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    $walk($v, $prefix . '.' . $k);
                }

                return;
            }
            $out[$prefix] = is_scalar($node) ? $this->normalise(['type' => 'text'], $node) : null;
        };
        $walk($value ?? [], $block);

        return $out;
    }
}
