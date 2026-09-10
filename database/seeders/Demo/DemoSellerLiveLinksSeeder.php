<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Presentation;
use App\Services\Presentations\PresentationCompilerService;
use App\Services\Presentations\SnapshotLinkService;
use Illuminate\Support\Facades\DB;

/**
 * Webinar prep 2026-09-02 — Johan: "seller live links working and showing
 * the graphs... There are several live examples, on properties that also
 * look complete... Exercise the actual link yourself, do not infer it works."
 *
 * Root cause found by reading the actual public route, not assumed: the
 * seller-facing "Seller Live" link Johan can open and share is the no-auth
 * `/p/{token}` route (PublicPresentationController::show, routes/web.php),
 * NOT the authenticated `/presentations/{id}/seller-live` route. It resolves
 * a `presentation_snapshot_links` row, which requires a compiled
 * `presentation_versions` row to exist (SnapshotLinkService::createLink()
 * throws "no compiled version yet" otherwise). Confirmed zero
 * presentation_snapshot_links existed anywhere in the demo before this —
 * the feature had never been exercised. DemoPresentationMarketDataSeeder
 * already fixed the underlying sold-comp/active-listing data these
 * versions compile from.
 *
 * Uses the SAME real services the "Compile" and "Share" buttons in the app
 * use (PresentationCompilerService::compile(), SnapshotLinkService::createLink())
 * — not a hand-rolled JSON payload — so the resulting version/link is
 * indistinguishable from one an agent created by hand.
 *
 * Idempotent: skips any presentation that already has an active (non-revoked,
 * non-expired) snapshot link.
 */
final class DemoSellerLiveLinksSeeder
{
    /** The finalized, fully-populated "hero" presentations built for the webinar. */
    private const HERO_PRESENTATION_IDS = [50, 51, 52, 53, 54, 55, 56, 57];

    public function run(int $agencyId): array
    {
        $presentations = Presentation::where('agency_id', $agencyId)
            ->whereIn('id', self::HERO_PRESENTATION_IDS)
            ->whereNull('deleted_at')
            ->get();

        if ($presentations->isEmpty()) {
            return ['note' => "Skipped — none of the hero presentation ids found for agency {$agencyId}."];
        }

        $compiler = app(PresentationCompilerService::class);
        $linkSvc  = app(SnapshotLinkService::class);

        $adminUserId = DB::table('users')
            ->where('agency_id', $agencyId)
            ->where('email', 'admin@demo.corexos.co.za')
            ->value('id') ?? DB::table('users')->where('agency_id', $agencyId)->value('id');

        $links = [];
        $compiled = 0;
        $created = 0;
        $skipped = 0;

        foreach ($presentations as $p) {
            $existing = DB::table('presentation_snapshot_links')
                ->where('presentation_id', $p->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $links[] = ['presentation_id' => $p->id, 'address' => $p->property_address, 'token' => $existing->token];
                $skipped++;
                continue;
            }

            $version = $compiler->compile($p->id, $adminUserId);
            $compiled++;

            $link = $linkSvc->createLink($p, [
                'version_id'          => $version->id,
                'mode'                => 'full',
                'recipient_label'     => $p->seller_name ?: 'Seller',
                'created_by_user_id'  => $adminUserId,
                'expires_at'          => now()->addDays(90),
            ]);
            $created++;

            $links[] = ['presentation_id' => $p->id, 'address' => $p->property_address, 'token' => $link->token];
        }

        return [
            'versions_compiled' => $compiled,
            'links_created'     => $created,
            'links_already_active' => $skipped,
            'links'             => $links,
        ];
    }
}
