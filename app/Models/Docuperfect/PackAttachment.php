<?php

namespace App\Models\Docuperfect;

use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackAttachment extends Model
{
    protected $table = 'docuperfect_pack_attachments';

    protected $fillable = [
        'pack_instance_id',
        'knowledge_document_id',
        'slot_label',
    ];

    public function knowledgeDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('pack_instance_id', $instanceId);
    }
}
