<?php

namespace App\Models\Docuperfect;

use App\Models\KnowledgeCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PackSlot extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_pack_slots';

    protected $fillable = [
        'pack_id',
        'sort_order',
        'label',
        'slot_type',
        'template_id',
        'document_type_id',
        'knowledge_category_id',
        'allow_multiple',
        'is_optional',
    ];

    protected $casts = [
        'allow_multiple' => 'boolean',
        'is_optional' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class, 'pack_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function knowledgeCategory(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'knowledge_category_id');
    }

    public function scopeRequired($query)
    {
        return $query->where('slot_type', 'required');
    }

    public function scopeSelectable($query)
    {
        return $query->where('slot_type', 'selectable');
    }

    public function scopeAttachment($query)
    {
        return $query->where('slot_type', 'attachment');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
