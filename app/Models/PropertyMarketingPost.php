<?php

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PropertyMarketingPost extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_FAILED     = 'failed';
    // AT-456 D — the Post Scheduler.
    public const STATUS_SCHEDULED  = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'agency_id',
        'property_id',
        'company_ad_id',
        'user_id',
        'platform',
        'platform_post_id',
        'ad_copy',
        'image_urls',
        'status',
        'published_at',
        'scheduled_for',
        'failure_reason',
        'last_synced_at',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'link_clicks',
    ];

    protected $casts = [
        'image_urls'     => 'array',
        'published_at'   => 'datetime',
        // AT-456 D — UTC wall-clock in the column, whatever the app timezone is.
        'scheduled_for'  => \App\Casts\UtcDateTime::class,
        'last_synced_at' => 'datetime',
        'impressions'    => 'integer',
        'reach'          => 'integer',
        'likes'          => 'integer',
        'comments'       => 'integer',
        'shares'         => 'integer',
        'link_clicks'    => 'integer',
    ];

    /**
     * Company Ads §4: a post is about a listing OR a company ad - exactly one of the two. Enforced here on every save,
     * so no caller can store a post that is about both or neither.
     */
    protected static function booted(): void
    {
        static::saving(function (self $post) {
            if (($post->property_id === null) === ($post->company_ad_id === null)) {
                throw new \LogicException('A marketing post must be for exactly one listing or one company ad.');
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** The company ad this post shows (archived ones too, so the history keeps its name). */
    public function companyAd(): BelongsTo
    {
        return $this->belongsTo(CompanyAd::class)->withTrashed();
    }

    public function isCompanyAd(): bool
    {
        return $this->company_ad_id !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): ?AgentSocialAccount
    {
        return AgentSocialAccount::where('user_id', $this->user_id)
            ->where('platform', $this->platform)
            ->active()
            ->first();
    }

    /**
     * AT-456 D — the posts a user may see on Scheduled Posts, decided in the query (never a hidden link).
     * Breadth is the user's PROPERTIES data scope (PermissionService::getDataScope), on top of the
     * global AgencyScope:
     *   all    → every post in the agency
     *   branch → posts for properties in the user's branch, plus posts the user scheduled themselves
     *   own    → posts for the user's own book (an assistant's book is their agent's, dataIdentityIds())
     *            plus posts they scheduled themselves
     * A company-ad post has no listing, so below 'all' it is only seen by whoever scheduled it (Company Ads §7).
     * No scope (null) → nothing.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') {
            return $query;
        }

        $ids = $user->dataIdentityIds();

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $query->where(function ($w) use ($branchId, $ids) {
                $w->whereIn('property_marketing_posts.user_id', $ids);
                if ($branchId) {
                    $w->orWhereIn('property_marketing_posts.property_id', Property::query()->where('branch_id', $branchId)->select('id'));
                }
            });
        }

        if ($scope === 'own') {
            return $query->where(function ($w) use ($ids) {
                $w->whereIn('property_marketing_posts.user_id', $ids)
                    ->orWhereIn('property_marketing_posts.property_id', Property::query()->whereIn('agent_id', $ids)->select('id'));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /** Copy and time may be changed only while the post is still waiting. */
    public function isEditableSchedule(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
