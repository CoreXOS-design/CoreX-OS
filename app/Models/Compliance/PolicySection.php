<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PolicySection (AT-29) — mirrors RmcpSection. renderedBody() does
 * {{key}} mail-merge with HTML escaping, verbatim from RMCP.
 */
class PolicySection extends Model
{
    use BelongsToAgency, SoftDeletes;

    const TYPE_SECTION         = 'section';
    const TYPE_SCHEDULE        = 'schedule';
    const TYPE_ANNEXURE        = 'annexure';
    const TYPE_ACKNOWLEDGEMENT = 'acknowledgement';

    protected $fillable = [
        'agency_id',
        'policy_version_id',
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
        return $this->belongsTo(PolicyVersion::class, 'policy_version_id');
    }

    // ── Methods ──

    public function renderedBody(array $variables): string
    {
        $html = $this->body_html;

        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', e($value), $html);
        }

        return $html;
    }
}
