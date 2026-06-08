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

        $user->load(['branch', 'agency']);

        // The agent's listings as a website shows them: For Sale first, then
        // Under Offer, then Sold (social proof). Each links to its live preview.
        $listings = Property::query()
            ->where('agent_id', $user->id)
            ->whereIn('status', ['active', 'pending', 'under_offer', 'sold'])
            ->orderByRaw("FIELD(status, 'active', 'pending', 'under_offer', 'sold')")
            ->latest('published_at')
            ->limit(60)
            ->get();

        // Published testimonials tagged to this agent (the website set).
        $testimonials = ContactTestimonial::query()
            ->where('agent_id', $user->id)
            ->where('published', true)
            ->latest('published_at')
            ->get();

        // The agent's published articles (their public website blog).
        $articles = $user->articles()
            ->where('is_published', true)
            ->latest('published_at')
            ->get();

        return view('corex.agents.live-preview', [
            'agent'        => $user,
            'agency'       => $user->agency,
            'listings'     => $listings,
            'testimonials' => $testimonials,
            'articles'     => $articles,
            'isSelf'       => $user->id === $viewer->id,
        ]);
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
