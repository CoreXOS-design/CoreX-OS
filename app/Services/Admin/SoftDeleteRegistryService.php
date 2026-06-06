<?php

namespace App\Services\Admin;

use App\Models\SoftDeleteRestoration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Discovers every model that uses Laravel's SoftDeletes trait and powers the
 * Admin → Soft Deletes Register. Restore-only — no force-delete path exists
 * here (non-negotiable #1). Spec: .ai/specs/soft-deletes-admin.md.
 *
 * Security model (§7 of the spec):
 *  - Non-owner users only ever see / restore agency-scoped models
 *    (BelongsToAgency). Their AgencyScope filters counts, lists and lookups to
 *    their own agency automatically.
 *  - Owner roles see every soft-deletable model.
 *  - The restore endpoint resolves the model only from the whitelist the
 *    current user is allowed to see — an arbitrary class in the URL is rejected.
 */
class SoftDeleteRegistryService
{
    private const SOFT_DELETES_TRAIT = SoftDeletes::class;
    private const AGENCY_TRAIT = \App\Models\Concerns\BelongsToAgency::class;

    /** Four pillar models grouped under one category, shown first. */
    private const PILLAR_CLASSES = [
        \App\Models\Property::class,
        \App\Models\Contact::class,
        \App\Models\Deal::class,
        \App\Models\User::class,
    ];

    /** Cached raw discovery (class => [agency_scoped]) for the request. */
    private static ?array $discovered = null;

    /**
     * Models the given user may see, as a collection of entries:
     * { key, class, label, category, agency_scoped }.
     */
    public function modelsFor(User $user): Collection
    {
        $isOwner = $user->isOwnerRole();

        return collect($this->discover())
            ->filter(fn (array $meta) => $isOwner || $meta['agency_scoped'])
            ->map(fn (array $meta, string $class) => [
                'key'           => $this->keyFor($class),
                'class'         => $class,
                'label'         => $this->labelFor($class),
                'category'      => $this->categoryFor($class),
                'agency_scoped' => $meta['agency_scoped'],
            ])
            ->values();
    }

    /**
     * Categories (ordered, Pillars first) → models that currently have at least
     * one archived record, each carrying its archived count. A model whose
     * count query throws is silently skipped so the page always renders.
     */
    public function categoriesWithCounts(User $user): Collection
    {
        $rows = $this->modelsFor($user)
            ->map(function (array $entry) {
                $count = $this->safeCount($entry['class']);
                if ($count === null || $count === 0) {
                    return null;
                }
                return array_merge($entry, ['count' => $count]);
            })
            ->filter()
            ->values();

        return $rows
            ->groupBy('category')
            ->map(fn (Collection $models, string $category) => [
                'category' => $category,
                'total'    => $models->sum('count'),
                'models'   => $models->sortBy('label')->values(),
            ])
            ->sortBy(fn (array $g) => $this->categoryOrder($g['category']))
            ->values();
    }

    public function totalArchived(User $user): int
    {
        return $this->categoriesWithCounts($user)->sum('total');
    }

    /**
     * Resolve a URL key to a FQCN, only if it is in the user's visible registry.
     */
    public function resolve(string $key, User $user): ?string
    {
        return $this->modelsFor($user)->firstWhere('key', $key)['class'] ?? null;
    }

    /**
     * Paginated archived records for one model class, newest-deleted first.
     */
    public function trashedRecords(string $class, int $perPage = 30)
    {
        return $class::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->paginate($perPage);
    }

    /**
     * Restore one archived record (scoped to the user) and write an audit row.
     * Returns false if the record is not found within the user's scope.
     */
    public function restore(string $class, int $id, User $user): bool
    {
        /** @var Model|null $record */
        $record = $class::onlyTrashed()->find($id);
        if (! $record) {
            return false;
        }

        $label = $this->recordLabel($record);
        $agencyId = $record->getAttribute('agency_id');

        $record->restore();

        SoftDeleteRestoration::create([
            'model_type'          => $class,
            'model_id'            => $id,
            'model_label'         => $label,
            'agency_id'           => $agencyId,
            'restored_by_user_id' => $user->id,
            'restored_at'         => Carbon::now(),
        ]);

        return true;
    }

    /**
     * Human label for a single archived record, via attribute priority.
     */
    public function recordLabel(Model $record): string
    {
        $candidates = [
            'name', 'full_name', 'display_name', 'title', 'subject',
            'reference', 'reference_no', 'deal_no', 'deal_number',
            'label', 'email',
        ];

        foreach ($candidates as $attr) {
            $value = $record->getAttribute($attr);
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 80);
            }
        }

        $first = $record->getAttribute('first_name');
        $last = $record->getAttribute('last_name');
        $name = trim(((string) $first) . ' ' . ((string) $last));
        if ($name !== '') {
            return $name;
        }

        return '#' . $record->getKey();
    }

    // ── internals ──────────────────────────────────────────────

    private function safeCount(string $class): ?int
    {
        try {
            return (int) $class::onlyTrashed()->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Scan app/Models for concrete Model subclasses using SoftDeletes.
     * Result cached per request.
     *
     * @return array<class-string, array{agency_scoped: bool}>
     */
    private function discover(): array
    {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

        $found = [];
        $appPath = app_path();

        foreach (File::allFiles(app_path('Models')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = Str::after($file->getPathname(), $appPath . DIRECTORY_SEPARATOR);
            $relative = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relative);
            $class = 'App\\' . $relative;

            if (! class_exists($class)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($class);
            } catch (\Throwable $e) {
                continue;
            }

            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }

            $traits = class_uses_recursive($class);
            if (! in_array(self::SOFT_DELETES_TRAIT, $traits, true)) {
                continue;
            }

            $found[$class] = [
                'agency_scoped' => in_array(self::AGENCY_TRAIT, $traits, true),
            ];
        }

        ksort($found);

        return self::$discovered = $found;
    }

    private function keyFor(string $class): string
    {
        return str_replace('\\', '.', Str::after($class, 'App\\Models\\'));
    }

    private function labelFor(string $class): string
    {
        return Str::plural(Str::headline(class_basename($class)));
    }

    private function categoryFor(string $class): string
    {
        if (in_array($class, self::PILLAR_CLASSES, true)) {
            return 'Pillars';
        }

        $relative = Str::after($class, 'App\\Models\\');
        if (! Str::contains($relative, '\\')) {
            return 'General';
        }

        return Str::headline(Str::beforeLast($relative, '\\'));
    }

    private function categoryOrder(string $category): string
    {
        // Pillars always first, General last, everything else alphabetical.
        return match ($category) {
            'Pillars' => '0',
            'General' => 'zzz',
            default   => '1' . $category,
        };
    }
}
