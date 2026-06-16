<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Company Settings — the Agency record's presentation-facing details
 * (trading name, registration numbers, contact block, logo, email
 * signature footer).
 *
 * Lives as its own admin section (mirroring Branch Assignments) instead
 * of being nested inside the tabbed settings page, so the Owner can
 * manage every agency's company identity from a single place and each
 * section stays small enough to reason about.
 */
class CompanySettingsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess();

        $user = auth()->user();
        $activeAgencyId = $user?->effectiveAgencyId();

        $agencies = $user?->isOwnerRole()
            ? Agency::orderBy('name')->get()
            : Agency::where('id', $activeAgencyId)->get();

        $requested = (int) $request->query('agency', 0);
        if ($requested && $user?->isOwnerRole()) {
            $agency = $agencies->firstWhere('id', $requested);
        } else {
            $agency = $activeAgencyId
                ? $agencies->firstWhere('id', $activeAgencyId) ?? $agencies->first()
                : $agencies->first();
        }

        $agents = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $branches = Branch::orderBy('name')->get();
        $vatRate = (float) PerformanceSetting::get('vat_rate', 15);
        $listingsPerSale = (float) PerformanceSetting::get('listings_per_sale', 5);

        // The Website tab is only shown when the agency has a live website
        // (at least one active API key). Activation happens under Admin →
        // Agencies → API Access.
        $websiteActive = $agency?->hasActiveWebsite() ?? false;

        return view('admin.company-settings.index', compact(
            'agencies', 'agency', 'agents', 'branches', 'vatRate', 'listingsPerSale', 'websiteActive'
        ));
    }

    public function update(Request $request, Agency $agency)
    {
        $this->authorizeAccess();
        $this->authorizeAgency($agency);

        $data = $request->validate([
            'trading_name'          => ['nullable', 'string', 'max:255'],
            'tagline'               => ['nullable', 'string', 'max:255'],
            'address'               => ['nullable', 'string', 'max:500'],
            'phone'                 => ['nullable', 'string', 'max:255'],
            'phone_label'           => ['nullable', 'string', 'max:100'],
            'phone_secondary'       => ['nullable', 'string', 'max:255'],
            'phone_secondary_label' => ['nullable', 'string', 'max:100'],
            'fax'                   => ['nullable', 'string', 'max:255'],
            'email'                 => ['nullable', 'string', 'max:255'],
            'reg_no'                => ['nullable', 'string', 'max:255'],
            'vat_no'                => ['nullable', 'string', 'max:255'],
            'ffc_no'                => ['nullable', 'string', 'max:255'],
            'ppra_number'           => ['nullable', 'string', 'max:32'],
            'public_contact'        => ['nullable', 'string', 'max:255'],
            'fic_no'                => ['nullable', 'string', 'max:255'],
            'email_disclaimer'      => ['nullable', 'string', 'max:2000'],
            'popi_url'              => ['nullable', 'string', 'max:500'],
            // Phase 9c-3 rebuild — privacy policy as Company Settings field.
            'privacy_policy_markdown' => ['nullable', 'string', 'max:200000'],
            'privacy_policy_action'   => ['nullable', 'string', 'in:publish,unpublish'],
            'sidebar_color'         => ['nullable', 'string', 'max:20'],
            'icon_color'            => ['nullable', 'string', 'max:20'],
            'default_color'         => ['nullable', 'string', 'max:20'],
            'button_color'          => ['nullable', 'string', 'max:20'],
            'logo'                  => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo'           => ['nullable', 'boolean'],
            // 2026-05-14 hotfix — agency-scoped WhatsApp launch modes.
            'whatsapp_launch_mode_agent'  => ['nullable', 'in:whatsapp_app,whatsapp_web'],
            'whatsapp_launch_mode_seller' => ['nullable', 'in:whatsapp_app,whatsapp_web'],
            // 2026-05-14 — pitch-claim integration: agency-tunable temp lock duration.
            'prospecting_pitch_temp_lock_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        // Privacy policy: content saves as draft; publish/unpublish are
        // explicit gestures. Token is generated lazily when first content
        // is saved and persists across edits.
        // TODO: token rotation endpoint (future) — for now token is fixed.
        $privacyAction = $data['privacy_policy_action'] ?? null;
        unset($data['privacy_policy_action']);
        if (array_key_exists('privacy_policy_markdown', $data)) {
            $hasContent = !empty($data['privacy_policy_markdown']);
            if ($hasContent && empty($agency->privacy_policy_token)) {
                $data['privacy_policy_token'] = $agency->generatePrivacyPolicyToken();
            }
            if (!$hasContent) {
                // Clearing the content auto-unpublishes — can't publish empty.
                $data['privacy_policy_published_at'] = null;
            }
        }
        if ($privacyAction === 'publish') {
            $data['privacy_policy_published_at'] = $agency->privacy_policy_published_at ?: now();
        } elseif ($privacyAction === 'unpublish') {
            $data['privacy_policy_published_at'] = null;
        }

        $removeLogo = $data['remove_logo'] ?? false;
        unset($data['logo'], $data['remove_logo']);

        if ($removeLogo) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $data['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "agencies/{$agency->id}", "logo.{$ext}", 'public'
            );
            $data['logo_path'] = $path;
        }

        $agency->update($data);

        return redirect()->route('admin.company-settings', ['agency' => $agency->id])
            ->with('success', 'Company settings updated.');
    }

    /**
     * Agency Public API — save the public website settings (Website tab). Its
     * own form/route so it never collides with the Company/Branding forms that
     * share update(). Spec: .ai/specs/agency-public-api.md §3.7, §7.4.
     */
    public function updateWebsite(Request $request, Agency $agency)
    {
        $this->authorizeAccess();
        $this->authorizeAgency($agency);

        $data = $request->validate([
            'website_social_facebook'  => ['nullable', 'string', 'max:255'],
            'website_social_instagram' => ['nullable', 'string', 'max:255'],
            'website_social_linkedin'  => ['nullable', 'string', 'max:255'],
            'website_social_youtube'   => ['nullable', 'string', 'max:255'],
            'website_contact_email'    => ['nullable', 'email', 'max:255'],
            'website_contact_phone'    => ['nullable', 'string', 'max:255'],
            'website_address'          => ['nullable', 'string', 'max:500'],
            'website_open_hours'       => ['nullable', 'array'],
            'website_open_hours.*.days'  => ['nullable', 'string', 'max:100'],
            'website_open_hours.*.hours' => ['nullable', 'string', 'max:100'],
            'website_agent_order_mode' => ['nullable', 'in:alphabetical,custom'],
            'agent_order'              => ['nullable', 'array'],
            'agent_order.*'            => ['nullable', 'integer', 'min:1', 'max:9999'],
            'website_branch_order_mode' => ['nullable', 'in:alphabetical,custom'],
            'branch_order'              => ['nullable', 'array'],
            'branch_order.*'            => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $agentOrder = $data['agent_order'] ?? [];
        unset($data['agent_order']);
        $branchOrder = $data['branch_order'] ?? [];
        unset($data['branch_order']);

        // Open hours — drop blank rows (both fields empty) and trim. Store null
        // when nothing remains so the public API omits the block entirely.
        $hours = [];
        foreach ($data['website_open_hours'] ?? [] as $row) {
            $days  = trim((string) ($row['days'] ?? ''));
            $hrs   = trim((string) ($row['hours'] ?? ''));
            if ($days !== '' || $hrs !== '') {
                $hours[] = ['days' => $days, 'hours' => $hrs];
            }
        }
        $data['website_open_hours'] = $hours ?: null;

        $data['website_show_agents']      = $request->boolean('website_show_agents');
        $data['website_show_listings']    = $request->boolean('website_show_listings');
        $data['website_show_branches']    = $request->boolean('website_show_branches');
        $data['website_agent_order_mode']  = $data['website_agent_order_mode'] ?? Agency::AGENT_ORDER_ALPHABETICAL;
        $data['website_branch_order_mode'] = $data['website_branch_order_mode'] ?? Agency::BRANCH_ORDER_ALPHABETICAL;

        $agency->update($data);

        // Persist per-agent positions (only this agency's users; ignore unknowns).
        if (!empty($agentOrder)) {
            $agencyUserIds = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->where('agency_id', $agency->id)->pluck('id')->all();
            foreach ($agentOrder as $userId => $position) {
                if (in_array((int) $userId, $agencyUserIds, true)) {
                    \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                        ->where('id', (int) $userId)
                        ->update(['website_order' => $position !== null && $position !== '' ? (int) $position : null]);
                }
            }
        }

        // Persist per-branch positions (only this agency's branches; ignore unknowns).
        if (!empty($branchOrder)) {
            $agencyBranchIds = \App\Models\Branch::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->where('agency_id', $agency->id)->pluck('id')->all();
            foreach ($branchOrder as $branchId => $position) {
                if (in_array((int) $branchId, $agencyBranchIds, true)) {
                    \App\Models\Branch::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                        ->where('id', (int) $branchId)
                        ->update(['website_order' => $position !== null && $position !== '' ? (int) $position : null]);
                }
            }
        }

        return redirect()->route('admin.company-settings', ['agency' => $agency->id, 'tab' => 'website'])
            ->with('success', 'Website settings updated.');
    }

    /**
     * Publish / unpublish a captured testimonial to the agency website (the
     * tick box in the Website tab → Testimonials section). The model observer
     * fires the testimonial.published / .removed webhook. Gated separately by
     * testimonials.publish (route middleware) so curation is a deliberate act.
     *
     * Spec: .ai/specs/testimonials.md §6.2, §7.
     */
    public function toggleTestimonial(Request $request, Agency $agency, \App\Models\ContactTestimonial $testimonial)
    {
        $this->authorizeAccess();
        $this->authorizeAgency($agency);

        // Defence-in-depth: the testimonial must belong to this agency.
        abort_unless((int) $testimonial->agency_id === (int) $agency->id, 404);

        $publish = $request->boolean('published');

        $testimonial->update([
            'published'            => $publish,
            'published_at'         => $publish ? ($testimonial->published_at ?: now()) : $testimonial->published_at,
            'published_by_user_id' => $publish ? auth()->id() : $testimonial->published_by_user_id,
        ]);

        return redirect()->route('admin.company-settings', ['agency' => $agency->id, 'tab' => 'website'])
            ->with('success', $publish ? 'Testimonial published to website.' : 'Testimonial removed from website.')
            ->withFragment('testimonials');
    }

    /**
     * "Push all Sold to website" — enable website syndication for every SOLD
     * listing in the agency, across all of its active websites (API keys), in
     * one action. Reuses the same per-listing syndication path (and webhooks)
     * as the property-level toggle. Sold-only; never touches active/draft stock.
     */
    public function pushSoldToWebsite(Request $request, Agency $agency, WebsiteSyndicationService $service)
    {
        $this->authorizeAccess();
        $this->authorizeAgency($agency);

        $back = redirect()
            ->route('admin.company-settings', ['agency' => $agency->id, 'tab' => 'website'])
            ->withFragment('website-sold');

        $keys = $agency->apiKeys()->get()->filter->isActive();
        if ($keys->isEmpty()) {
            return $back->with('success', 'This agency has no active website yet — add one under API Access first.');
        }

        $soldCount = Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('status', 'sold')
            ->count();

        if ($soldCount === 0) {
            return $back->with('success', 'No sold listings to push.');
        }

        $enabled = 0;
        $alreadyLive = 0;
        foreach ($keys as $key) {
            $summary = $service->bulkActivateSold($key);
            $enabled += $summary['enabled'];
            $alreadyLive += $summary['already_live'];
        }

        $siteWord = $keys->count() === 1 ? 'website' : "{$keys->count()} websites";
        $message = "Pushed {$soldCount} sold listing(s) to {$siteWord}."
            . ($alreadyLive > 0 ? " ({$enabled} newly added, {$alreadyLive} already live.)" : '');

        return $back->with('success', $message);
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);
    }

    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        if ($user->isOwnerRole()) {
            return;
        }

        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only edit your own agency.');
        }
    }
}
