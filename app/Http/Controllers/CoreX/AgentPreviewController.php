<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\AgentArticle;
use App\Models\ContactTestimonial;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * CoreX live preview of an agent's public website profile — the agent
 * equivalent of the property live-preview page. Renders exactly the data an
 * agency website would pull for this agent (profile, listings, published
 * testimonials) so the agent can see their public page before / while it is
 * live, without leaving CoreX.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage) + agency-public-api.md §5.
 */
class AgentPreviewController extends Controller
{
    public function show(Request $request, User $user)
    {
        $viewer = auth()->user();
        abort_unless($viewer !== null, 403);

        // Authorization + multi-tenancy: the agent may preview themselves; a
        // manager/owner may preview any agent in their own agency.
        $sameAgency    = (int) $user->agency_id === (int) $viewer->effectiveAgencyId();
        $canViewOthers = $viewer->isOwnerRole() || $viewer->hasPermission('manage_users');
        abort_unless(
            $user->id === $viewer->id || $viewer->isOwnerRole() || ($sameAgency && $canViewOthers),
            403,
            'You can only preview your own agent page.'
        );

        return view('corex.agents.live-preview', array_merge(
            $this->agentPageData($user),
            ['isSelf' => $user->id === $viewer->id, 'isPublic' => false]
        ));
    }

    /**
     * Public agent profile — the QR-code / shareable target. No auth: a
     * prospect who scans an agent's card lands here. The agent is resolved by
     * the trailing qr_code_slug ({tag}), which also follows the departed-agent
     * reroute chain; the {nameSlug} segment is cosmetic and canonicalised via
     * redirect on mismatch (e.g. after a rename).
     *
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public function publicShow(Request $request, string $nameSlug, string $tag)
    {
        $agent = User::resolveByQrSlug($tag);
        abort_unless($agent, 404);

        // Keep the pretty URL honest — redirect to the canonical name slug.
        if ($nameSlug !== $agent->nameSlug()) {
            return redirect()->route('corex.agents.public', [$agent->nameSlug(), $tag]);
        }

        return view('corex.agents.live-preview', array_merge(
            $this->agentPageData($agent),
            ['isSelf' => false, 'isPublic' => true, 'publicTag' => $tag]
        ));
    }

    /** Public single-article view, reached from the public profile page. */
    public function publicArticle(Request $request, string $nameSlug, string $tag, AgentArticle $article)
    {
        $agent = User::resolveByQrSlug($tag);
        abort_unless($agent, 404);
        abort_unless((int) $article->user_id === (int) $agent->id && (bool) $article->is_published, 404);

        if ($nameSlug !== $agent->nameSlug()) {
            return redirect()->route('corex.agents.public.article', [$agent->nameSlug(), $tag, $article]);
        }

        $agent->load(['branch', 'agency']);

        return view('corex.agents.article-preview', [
            'agent'      => $agent,
            'agency'     => $agent->agency,
            'article'    => $article,
            'profileUrl' => $agent->publicProfileUrl(),
            'shareUrl'   => route('corex.agents.public.article', [$agent->nameSlug(), $tag, $article]),
        ]);
    }

    /**
     * Backwards-compat redirect for the original QR URL (/r/a/{slug}) printed
     * on cards/signage in the wild. Resolves the slug and 301s to the canonical
     * public profile so old codes never dead-end.
     */
    public function legacyQrRedirect(string $slug)
    {
        $agent = User::resolveByQrSlug($slug);
        abort_unless($agent, 404);

        return redirect()->route('corex.agents.public', [$agent->nameSlug(), $slug], 301);
    }

    /**
     * Shared data set for the agent public page — the exact slice a website
     * would pull: profile + listings (For Sale → Under Offer → Sold) +
     * published testimonials + published articles.
     */
    private function agentPageData(User $user): array
    {
        $user->load(['branch', 'agency']);

        $listings = Property::query()
            ->where('agent_id', $user->id)
            ->whereIn('status', ['active', 'pending', 'under_offer', 'sold'])
            ->orderByRaw("FIELD(status, 'active', 'pending', 'under_offer', 'sold')")
            ->latest('published_at')
            ->limit(60)
            ->get();

        $testimonials = ContactTestimonial::query()
            ->where('agent_id', $user->id)
            ->where('published', true)
            ->latest('published_at')
            ->get();

        $articles = $user->articles()
            ->where('is_published', true)
            ->latest('published_at')
            ->get();

        return [
            'agent'        => $user,
            'agency'       => $user->agency,
            'listings'     => $listings,
            'testimonials' => $testimonials,
            'articles'     => $articles,
        ];
    }

    /** Full preview of a single article (the "click to read" view). */
    public function article(Request $request, User $user, AgentArticle $article)
    {
        $viewer = auth()->user();
        abort_unless($viewer !== null, 403);
        abort_unless((int) $article->user_id === (int) $user->id, 404);

        $sameAgency    = (int) $user->agency_id === (int) $viewer->effectiveAgencyId();
        $canViewOthers = $viewer->isOwnerRole() || $viewer->hasPermission('manage_users');
        abort_unless(
            $user->id === $viewer->id || $viewer->isOwnerRole() || ($sameAgency && $canViewOthers),
            403,
            'You can only preview your own articles.'
        );

        $user->load(['branch', 'agency']);

        return view('corex.agents.article-preview', [
            'agent'   => $user,
            'agency'  => $user->agency,
            'article' => $article,
        ]);
    }
}
