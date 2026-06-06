<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
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

        // The agent's listings, exactly as a website would show them (active
        // stock, newest first). Each links through to its own live preview.
        $listings = Property::query()
            ->where('agent_id', $user->id)
            ->where('status', 'active')
            ->latest('published_at')
            ->limit(24)
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
}
