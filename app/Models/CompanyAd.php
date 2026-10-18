<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company ad — an agency brand ad that is not about a listing (season's greetings, holiday closure,
 * welcome a new agent). Spec: .ai/specs/company-ads.md.
 *
 * Agency-wide by design: everyone in the agency with access_ad_manager sees and downloads them; changing
 * one needs `ad_manager.company_ads`. AgencyScope keeps another agency's ads unresolvable (404).
 * layout_json is the same element shape the Ad Builder / render kernel use (ad-manager.md §3, §12), minus
 * every listing element (see CompanyAdLayout).
 */
class CompanyAd extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const PERMISSION = 'ad_manager.company_ads';

    /** The four ad sizes (same keys as CANVAS_PRESETS in public/js/corex-ad-render.js). */
    public const CANVASES = ['facebook', 'instagram', 'story', 'whatsapp'];

    protected $fillable = [
        'agency_id', 'user_id', 'name', 'occasion', 'canvas', 'layout_json', 'caption', 'ai_generated', 'ai_idea',
    ];

    protected $casts = [
        'layout_json'  => 'array',
        'ai_generated' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** May this user change company ads? One key covers every company ad in the agency (spec §9). */
    public static function userMayManage(?User $user): bool
    {
        return $user !== null
            && $user->hasPermission('access_ad_manager')
            && $user->hasPermission(self::PERMISSION);
    }
}
