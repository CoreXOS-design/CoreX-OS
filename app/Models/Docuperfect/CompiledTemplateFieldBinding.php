<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-177 / WS0 — a row in the thin, DERIVED field-binding index (spec §12 ruling 2).
 *
 * Rebuilt from the compiled template's CDS `structure` on every publish; never the source
 * of truth. Exists to answer "which templates bind dictionary key X?" cheaply. No soft
 * deletes: it is disposable and reconstructable.
 */
class CompiledTemplateFieldBinding extends Model
{
    protected $table = 'compiled_template_field_bindings';

    protected $fillable = [
        'compiled_template_id',
        'agency_id',
        'block_id',
        'field_id',
        'field_label',
        'dictionary_key',
        'dictionary_version',
        'source',
    ];

    protected $casts = [
        'dictionary_version' => 'integer',
    ];

    public function compiledTemplate(): BelongsTo
    {
        return $this->belongsTo(CompiledTemplate::class);
    }
}
