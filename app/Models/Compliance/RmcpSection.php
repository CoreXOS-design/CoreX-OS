<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class RmcpSection extends Model
{
    use BelongsToAgency, SoftDeletes;

    const TYPE_SECTION        = 'section';
    const TYPE_SCHEDULE       = 'schedule';
    const TYPE_ANNEXURE       = 'annexure';
    const TYPE_ACKNOWLEDGEMENT = 'acknowledgement';

    protected $fillable = [
        'agency_id',
        'rmcp_version_id',
        'section_type',
        'display_order',
        'section_number',
        'title',
        'body_html',
        'requires_acknowledgement',
        'acknowledgement_prompt',
    ];

    protected $casts = [
        'display_order'            => 'integer',
        'requires_acknowledgement' => 'boolean',
    ];

    // ── Relationships ──

    public function version(): BelongsTo
    {
        return $this->belongsTo(RmcpVersion::class, 'rmcp_version_id');
    }

    // ── Methods ──

    /** "12. Legal persons" — or just "Approval" when the number IS the title (the approval block, AT-506). */
    public function heading(): string
    {
        return strcasecmp((string) $this->section_number, (string) $this->title) === 0
            ? (string) $this->title
            : $this->section_number . '. ' . $this->title;
    }

    public function renderedBody(array $variables): string
    {
        $html = $this->body_html;

        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', e($value), $html);
        }

        return $html;
    }
}
