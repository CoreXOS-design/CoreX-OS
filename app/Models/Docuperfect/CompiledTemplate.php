<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Events\Esign\TemplatePublished;
use App\Models\User;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * AT-177 / WS0 — an immutable, content-hashed, versioned compiled template (spec §2, §5).
 *
 * The `structure` column holds the CDS v2 tree ({@see Cds}), the SOLE runtime truth. A
 * PUBLISHED row is immutable: editing is a new version, never an in-place mutation (the
 * boot guard enforces it). A signing request pins (id, version, content_hash), so the
 * freshness class is unrepresentable.
 *
 * agency_id NULL = CoreX-standard template (Door A). This model deliberately does NOT use
 * BelongsToAgency: the AgencyScope treats agency_id NULL as an orphan and would hide the
 * shipped standard pack. Scoping is explicit via {@see scopeForAgency()} (own + standard),
 * mirroring the Docuperfect\Template convention.
 */
class CompiledTemplate extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    public const LINT_PENDING = 'pending';
    public const LINT_PASSED = 'passed';
    public const LINT_FAILED = 'failed';

    protected $table = 'compiled_templates';

    protected $fillable = [
        'agency_id',
        'source_template_id',
        'family',
        'version',
        'content_hash',
        'data_dictionary_version',
        'legal_class',
        'delivery_modes',
        'structure',
        'render_parity',
        'lint_report',
        'lint_status',
        'status',
        'published_at',
        'published_by',
        'compiled_by',
        'superseded_by_id',
    ];

    protected $casts = [
        'delivery_modes' => 'array',
        'structure' => 'array',
        'render_parity' => 'array',
        'lint_report' => 'array',
        'version' => 'integer',
        'data_dictionary_version' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * App-layer defaults so a freshly created instance is coherent in memory (Eloquent does
     * NOT hydrate DB column defaults back onto the object after create()).
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'lint_status' => self::LINT_PENDING,
        'legal_class' => 'general',
        'version' => 1,
        'data_dictionary_version' => 1,
    ];

    protected static function booted(): void
    {
        // Immutability invariant (§5): a PUBLISHED row is never updated — only superseded.
        static::updating(function (CompiledTemplate $model): void {
            if ($model->getOriginal('status') !== self::STATUS_PUBLISHED) {
                return; // draft rows are freely editable in the compile studio
            }

            $allowed = ['status', 'superseded_by_id', 'updated_at', 'deleted_at'];
            $illegal = array_diff(array_keys($model->getDirty()), $allowed);
            if ($illegal !== []) {
                throw new RuntimeException(
                    "Published CompiledTemplate #{$model->id} is immutable; cannot mutate ["
                    .implode(', ', $illegal).']. Publish a new version instead (AT-177 §5).'
                );
            }

            if ($model->isDirty('status') && $model->status !== self::STATUS_SUPERSEDED) {
                throw new RuntimeException(
                    "Published CompiledTemplate #{$model->id} may only transition to superseded, not back to draft."
                );
            }
        });
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function agency(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Agency::class);
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'source_template_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function compiledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function fieldBindings(): HasMany
    {
        return $this->hasMany(CompiledTemplateFieldBinding::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** CoreX-standard (Door A) templates only. */
    public function scopeStandard(Builder $query): Builder
    {
        return $query->whereNull('agency_id');
    }

    /** Templates an agency may use: its own compiled docs OR the CoreX-standard pack. */
    public function scopeForAgency(Builder $query, ?int $agencyId): Builder
    {
        return $query->where(function (Builder $q) use ($agencyId): void {
            $q->whereNull('agency_id');
            if ($agencyId !== null) {
                $q->orWhere('agency_id', $agencyId);
            }
        });
    }

    public function scopeFamily(Builder $query, string $family): Builder
    {
        return $query->where('family', $family);
    }

    // ── CDS access ──────────────────────────────────────────────────────────

    /** The typed canonical structure. Hydrated from the immutable JSON column. */
    public function cds(): Cds
    {
        return Cds::fromArray($this->structure ?? []);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    // ── Versioned publish (§5) ───────────────────────────────────────────────

    /**
     * Freeze this draft as an immutable published version.
     *
     *  - requires the linter gate to have passed (lint_status = passed)
     *  - stamps the content_hash from the CDS (the §5 pin)
     *  - assigns the next monotonic version for this (agency_id, family)
     *  - supersedes the prior published version of the same family (never deletes it — NN#1)
     *  - rebuilds the thin field-binding index
     *  - emits TemplatePublished (the integration moat)
     */
    public function publishAsNewVersion(?User $publisher = null): self
    {
        if ($this->lint_status !== self::LINT_PASSED) {
            throw new RuntimeException(
                "CompiledTemplate #{$this->id} cannot publish: lint_status is [{$this->lint_status}], not passed (AT-177 §4)."
            );
        }
        if ($this->isPublished()) {
            throw new RuntimeException("CompiledTemplate #{$this->id} is already published.");
        }

        $hash = $this->cds()->contentHash();

        $duplicate = static::query()
            ->published()
            ->where('content_hash', $hash)
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException(
                "Identical content already published as CompiledTemplate #{$duplicate->id} (version {$duplicate->version}); nothing to publish."
            );
        }

        $prior = static::query()
            ->where('agency_id', $this->agency_id)
            ->family($this->family)
            ->published()
            ->orderByDesc('version')
            ->first();

        $this->version = ($prior?->version ?? 0) + 1;
        $this->content_hash = $hash;
        // The CDS is authoritative for the pinned dictionary version — align the column to it
        // (the DB default does not hydrate the in-memory instance after create()).
        $this->data_dictionary_version = $this->cds()->dataDictionaryVersion;
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = now();
        $this->published_by = $publisher?->id ?? $this->published_by;
        $this->save();

        if ($prior !== null) {
            $prior->status = self::STATUS_SUPERSEDED;
            $prior->superseded_by_id = $this->id;
            $prior->save();
        }

        $this->syncFieldBindings();

        event(new TemplatePublished($this, $publisher?->id));

        return $this;
    }

    /**
     * Rebuild the derived field-binding index from the CDS structure (§12 ruling 2).
     * The index is never authoritative — this reconstructs it from the sole truth.
     */
    public function syncFieldBindings(): void
    {
        $this->fieldBindings()->delete();

        $cds = $this->cds();
        $dictionaryVersion = $this->data_dictionary_version ?? $cds->dataDictionaryVersion;

        $rows = [];
        foreach ($cds->blocks() as $block) {
            foreach ($block->fields as $field) {
                /** @var Field $field */
                $rows[] = [
                    'compiled_template_id' => $this->id,
                    'agency_id' => $this->agency_id,
                    'block_id' => $block->blockId,
                    'field_id' => $field->fieldId,
                    'field_label' => $field->label !== '' ? $field->label : null,
                    'dictionary_key' => $field->binding,
                    'dictionary_version' => $dictionaryVersion,
                    'source' => $field->source->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            CompiledTemplateFieldBinding::query()->insert($rows);
        }
    }
}
