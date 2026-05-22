<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\Presentation;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Phase 4 Part B — generation + management of public snapshot share links.
 *
 * Token: 48-char base62 from Str::random — entropy ≈ 2^286, no enumeration
 * risk. Treated as a password in logs / UI (PresentationSnapshotLink::maskedToken).
 */
final class SnapshotLinkService
{
    public const TOKEN_LENGTH = 48;

    /**
     * Create a new snapshot link.
     *
     * @param array{
     *   version_id?: int|null,
     *   mode?: string,
     *   recipient_contact_id?: int|null,
     *   recipient_label?: string|null,
     *   expires_at?: \DateTimeInterface|string|null,
     *   created_by_user_id: int,
     * } $options
     */
    public function createLink(Presentation $presentation, array $options): PresentationSnapshotLink
    {
        if (empty($options['created_by_user_id'])) {
            throw new \InvalidArgumentException('created_by_user_id is required.');
        }

        $versionId = $options['version_id'] ?? null;
        if ($versionId === null) {
            $version = $presentation->versions()->latest('id')->first();
            if (!$version) {
                throw new \RuntimeException('Cannot create snapshot link: presentation has no compiled version yet.');
            }
            $versionId = (int) $version->id;
        }

        $mode = $options['mode'] ?? 'full';
        if (!in_array($mode, ['full', 'teaser'], true)) {
            throw new \InvalidArgumentException("Invalid mode '{$mode}'.");
        }

        $expiresAt = $this->resolveExpiry($presentation, $options['expires_at'] ?? null);

        return PresentationSnapshotLink::create([
            'presentation_id'         => $presentation->id,
            'presentation_version_id' => $versionId,
            'agency_id'               => $presentation->agency_id,
            'token'                   => $this->generateUniqueToken(),
            'mode'                    => $mode,
            'recipient_contact_id'    => $options['recipient_contact_id'] ?? null,
            'recipient_label'         => $options['recipient_label']      ?? null,
            'created_by_user_id'      => (int) $options['created_by_user_id'],
            'expires_at'              => $expiresAt,
        ]);
    }

    public function revokeLink(PresentationSnapshotLink $link, User $by): void
    {
        if ($link->revoked_at) return;
        $link->forceFill([
            'revoked_at'         => now(),
            'revoked_by_user_id' => $by->id,
        ])->save();
    }

    public function extendExpiry(PresentationSnapshotLink $link, int $days, User $by): void
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException('Days must be positive.');
        }
        // If the link already expired, push from now; otherwise extend from its current expiry.
        $from = $link->expires_at && $link->expires_at->isFuture() ? $link->expires_at : now();
        $link->forceFill([
            'expires_at' => $from->copy()->addDays($days),
        ])->save();
    }

    /** @return Collection<int, PresentationSnapshotLink> */
    public function listForPresentation(Presentation $presentation): Collection
    {
        return $presentation->snapshotLinks()
            ->whereNull('revoked_at')
            ->with(['recipientContact:id,first_name,last_name', 'creator:id,name'])
            ->withCount('teaserLeads')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Aggregate engagement stats across all (non-revoked) links of a presentation.
     *
     * @return array{
     *   total_links: int,
     *   total_views: int,
     *   unique_links_viewed: int,
     *   last_viewed_at: ?Carbon,
     *   avg_duration_seconds: ?int,
     *   any_flagged: bool,
     * }
     */
    public function engagementSummary(Presentation $presentation): array
    {
        $links = $presentation->snapshotLinks()->whereNull('revoked_at')->get();
        if ($links->isEmpty()) {
            return [
                'total_links'          => 0,
                'total_views'          => 0,
                'unique_links_viewed'  => 0,
                'last_viewed_at'       => null,
                'avg_duration_seconds' => null,
                'any_flagged'          => false,
            ];
        }

        $linkIds = $links->pluck('id')->all();
        $rawAvg = \Illuminate\Support\Facades\DB::table('presentation_snapshot_views')
            ->whereIn('snapshot_link_id', $linkIds)
            ->whereNotNull('duration_seconds')
            ->avg('duration_seconds');

        return [
            'total_links'          => $links->count(),
            'total_views'          => (int) $links->sum('view_count'),
            'unique_links_viewed'  => $links->where('view_count', '>', 0)->count(),
            'last_viewed_at'       => $links->whereNotNull('last_viewed_at')->max('last_viewed_at'),
            'avg_duration_seconds' => $rawAvg !== null ? (int) round((float) $rawAvg) : null,
            'any_flagged'          => $links->whereNotNull('flagged_at')->isNotEmpty(),
        ];
    }

    /**
     * Build the public share URL for a token.
     */
    public function publicUrl(string $token): string
    {
        return route('presentation.public.show', ['token' => $token]);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function generateUniqueToken(): string
    {
        // Retry on the off-chance of a collision (62^48 → virtually impossible
        // but belt-and-braces — the UNIQUE constraint will throw if it happens).
        for ($i = 0; $i < 5; $i++) {
            $token = Str::random(self::TOKEN_LENGTH);
            if (!PresentationSnapshotLink::where('token', $token)->exists()) {
                return $token;
            }
        }
        throw new \RuntimeException('Could not generate a unique snapshot link token after 5 attempts.');
    }

    private function resolveExpiry(Presentation $presentation, mixed $explicit): Carbon
    {
        if ($explicit !== null) {
            return Carbon::parse($explicit);
        }
        $days = (int) (Agency::find($presentation->agency_id)?->snapshot_link_default_expiry_days ?? 21);
        return now()->addDays($days);
    }
}
