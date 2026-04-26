<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgentSocialAccount;
use App\Models\ContactSource;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\Designation;
use App\Models\PropertySettingItem;
use App\Models\Docuperfect\NamedField;
use App\Models\PerformanceSetting;
use App\Models\Rental\RentalDocumentType;
use App\Models\Rental\RentalReminderSetting;
use App\Models\Branch;
use App\Models\User;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\UserDashboardSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $data = [
            'activeTab'      => $request->get('tab', 'agency'),
            'featureSection' => $request->get('fsec', 'documents'),
        ];

        // User Settings tab: Designations
        $data['designations'] = $user?->hasPermission('manage_designations')
            ? Designation::orderBy('sort_order')->orderBy('name')->get()
            : collect([]);

        // User Settings tab: Social Media Accounts
        if ($user) {
            $data['agentSocialAccounts'] = AgentSocialAccount::where('user_id', $user->id)
                ->active()
                ->get();
            $fbAccount = $data['agentSocialAccounts']->firstWhere('platform', 'facebook');
            $data['socialAccountExpiringSoon'] = $fbAccount
                && $fbAccount->token_expires_at
                && $fbAccount->token_expires_at->lessThan(now()->addDays(7));
        }

        // Feature Settings tab: Docuperfect
        $data['namedFields'] = NamedField::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Rentals
        $data['rentalDocTypes']         = RentalDocumentType::orderBy('sort_order')->get();
        $data['rentalReminderSettings'] = RentalReminderSetting::current();

        // Feature Settings tab: Contacts
        $data['contactTypes']   = ContactType::orderBy('sort_order')->orderBy('name')->get();
        $data['contactSources'] = ContactSource::orderBy('sort_order')->orderBy('name')->get();
        $data['contactTags']    = ContactTag::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Properties
        $data['propCategories']   = PropertySettingItem::group('category')->get();
        $data['propTypes']        = PropertySettingItem::group('property_type')->get();
        $data['propStatuses']     = PropertySettingItem::group('property_status')->get();
        $data['propMandateTypes'] = PropertySettingItem::group('mandate_type')->get();

        // Feature Settings tab: Properties — marketing toggle
        $data['marketingEnabled'] = (bool) PerformanceSetting::get('marketing_enabled', 1);

        // Feature Settings tab: Properties — syndication portal availability
        $data['syndicationWebsiteEnabled'] = (bool) PerformanceSetting::get('syndication_website_enabled', 1);
        $data['syndicationPpEnabled']      = (bool) PerformanceSetting::get('syndication_pp_enabled', 1);
        $data['syndicationP24Enabled']     = (bool) PerformanceSetting::get('syndication_p24_enabled', 1);

        // Feature Settings tab: Matches
        $data['matchesEnabled']            = (bool) PerformanceSetting::get('matches_enabled', 1);
        $data['matchesShowOnProperties']   = (bool) PerformanceSetting::get('matches_show_on_properties', 1);
        $defaultWaMsg = "Hi {name}! 👋\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!";
        $data['matchesWaMessage'] = (string) PerformanceSetting::get('matches_wa_message', $defaultWaMsg);

        // Agency Settings tab: Agency record + Performance Settings
        if ($user?->hasPermission('manage_performance_settings')) {
            $data['vatRate']         = (float)  PerformanceSetting::get('vat_rate', 15);
            $data['listingsPerSale'] = (float)  PerformanceSetting::get('listings_per_sale', 5);
        }

        // Agency Settings tab: Company details from Agency model
        $agencyId = $user?->effectiveAgencyId();
        $data['agency'] = $agencyId ? Agency::find($agencyId) : Agency::first();

        // Agents list for email signature preview selector
        $data['agents'] = User::agencyMembers()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Feature Settings tab: Dashboard — settings mode + agency dashboard settings
        $data['dashboardSettingsMode'] = $data['agency']->dashboard_settings_mode ?? 'user';
        $data['agencyDashboardSettings'] = $data['agency']
            ? AgencyDashboardSetting::firstOrNew(['agency_id' => $data['agency']->id], UserDashboardSetting::defaults())
            : new AgencyDashboardSetting(UserDashboardSetting::defaults());

        return view('corex.settings', $data);
    }

    // ── Property Setting Items CRUD ─────────────────────────────────────────

    public function storePropertySettingItem(Request $request)
    {
        $data = $request->validate([
            'group'      => 'required|in:category,property_type,property_status,mandate_type',
            'name'       => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        if (empty($data['sort_order'])) {
            $data['sort_order'] = (PropertySettingItem::where('group', $data['group'])->max('sort_order') ?? 0) + 1;
        }
        PropertySettingItem::create($data);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item added.');
    }

    public function updatePropertySettingItem(Request $request, PropertySettingItem $item)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $item->update($data);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item updated.');
    }

    public function reorderPropertySettingItems(Request $request)
    {
        $data = $request->validate([
            'items'              => 'required|array',
            'items.*.id'         => 'required|integer|exists:property_setting_items,id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($data['items'] as $item) {
            PropertySettingItem::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['ok' => true]);
    }

    public function batchToggleDefaultItems(Request $request, string $group)
    {
        $allowed = ['category', 'property_type', 'property_status', 'mandate_type'];
        if (! in_array($group, $allowed)) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('error', 'Invalid group.');
        }

        $enabledIds = array_map('intval', $request->input('enabled_ids', []));

        PropertySettingItem::where('group', $group)
            ->where('is_default', true)
            ->get()
            ->each(fn($item) => $item->update(['active' => in_array($item->id, $enabledIds)]));

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Updated successfully.');
    }

    public function togglePropertySettingItem(PropertySettingItem $item)
    {
        $item->update(['active' => ! $item->active]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item updated.');
    }

    public function destroyPropertySettingItem(PropertySettingItem $item)
    {
        if ($item->is_default) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('error', 'Default items cannot be deleted — use the toggle to disable them instead.');
        }
        $item->delete();
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item deleted.');
    }

    public function updateMarketingEnabled(Request $request)
    {
        $enabled = $request->boolean('marketing_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'marketing_enabled'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Marketing setting updated.');
    }

    public function updateSyndicationPortals(Request $request)
    {
        foreach (['syndication_website_enabled', 'syndication_pp_enabled', 'syndication_p24_enabled'] as $key) {
            PerformanceSetting::updateOrCreate(['key' => $key], ['value' => $request->boolean($key) ? 1 : 0]);
        }
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Syndication portals updated.');
    }

    public function updateMatchesEnabled(Request $request)
    {
        $enabled = $request->boolean('matches_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'Core Matches setting updated.');
    }

    public function updateMatchesShowOnProperties(Request $request)
    {
        $enabled = $request->boolean('matches_show_on_properties');
        PerformanceSetting::updateOrCreate(['key' => 'matches_show_on_properties'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'Setting updated.');
    }

    public function updateMatchesWaMessage(Request $request)
    {
        $message = substr($request->input('matches_wa_message', ''), 0, 1000);
        PerformanceSetting::updateOrCreate(['key' => 'matches_wa_message'], ['value' => $message]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'WhatsApp message template saved.');
    }

    public function generateApiToken(Request $request)
    {
        $user = $request->user();

        // Revoke all existing Sanctum tokens
        $user->tokens()->delete();

        // Create new Sanctum token
        $token = $user->createToken('corex-extension');

        // Store hash in api_token column for backwards compatibility
        $user->api_token = hash('sha256', explode('|', $token->plainTextToken)[1]);
        $user->save();

        return response()->json(['token' => $token->plainTextToken]);
    }

    // ── Agency Company Settings ───────────────────────────────────────────

    public function updateAgency(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();
        $agency = $agencyId ? Agency::findOrFail($agencyId) : Agency::firstOrFail();

        $data = $request->validate([
            'trading_name'     => ['nullable', 'string', 'max:255'],
            'tagline'          => ['nullable', 'string', 'max:255'],
            'address'          => ['nullable', 'string', 'max:500'],
            'phone'                  => ['nullable', 'string', 'max:255'],
            'phone_label'            => ['nullable', 'string', 'max:100'],
            'phone_secondary'        => ['nullable', 'string', 'max:255'],
            'phone_secondary_label'  => ['nullable', 'string', 'max:100'],
            'fax'              => ['nullable', 'string', 'max:255'],
            'email'            => ['nullable', 'string', 'max:255'],
            'reg_no'           => ['nullable', 'string', 'max:255'],
            'vat_no'           => ['nullable', 'string', 'max:255'],
            'ffc_no'           => ['nullable', 'string', 'max:255'],
            'fic_no'           => ['nullable', 'string', 'max:255'],
            'email_disclaimer' => ['nullable', 'string', 'max:2000'],
            'popi_url'         => ['nullable', 'string', 'max:500'],
            'logo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo'      => ['nullable', 'boolean'],
        ]);

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

        return redirect()->back()->with('success', 'Company settings updated.');
    }

    // ── Live Previews ─────────────────────────────────────────────────

    /**
     * Render document header preview with query param overrides.
     */
    public function previewHeader(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $agency = Agency::where('slug', 'hfc-coastal')->first();

        // Build a temporary agency-like object with query param overrides
        $overrides = $request->only([
            'trading_name', 'tagline', 'address', 'phone', 'phone_label',
            'phone_secondary', 'phone_secondary_label', 'fax', 'email',
            'reg_no', 'vat_no', 'ffc_no', 'fic_no', 'name',
        ]);

        // Create a clone with overrides applied
        $previewAgency = clone $agency;
        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $previewAgency->{$key} = $value;
            }
        }

        // Build a minimal branch (null — will use agency fallback)
        $branch = null;

        // Determine logo URL
        $logoUrl = $previewAgency->logo_path
            ? asset('storage/' . $previewAgency->logo_path)
            : null;

        $html = view('docuperfect.web-templates.components.company-header', [
            'previewAgency'  => $previewAgency,
            'header_display' => 'all_pages',
            'logo_url'       => $logoUrl,
        ])->render();

        // Wrap in a minimal HTML shell so the iframe renders cleanly
        $shell = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body { font-family: Arial, sans-serif; margin: 8px; }'
            . '</style></head><body>' . $html . '</body></html>';

        return response($shell)->header('Content-Type', 'text/html');
    }

    /**
     * Render email signature preview for a given user.
     */
    public function previewSignature(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $userId = $request->input('user_id');
        $agent = $userId ? User::find($userId) : auth()->user();

        $agency = Agency::where('slug', 'hfc-coastal')->first();

        $agentFooter = [
            'name'             => $agent->name ?? 'Agent Name',
            'email'            => $agent->email ?? '',
            'phone'            => $agent->phone ?? null,
            'designation'      => $agent->designation ?? null,
            'cell'             => $agent->cell ?? null,
            'fax'              => $agent->fax ?? null,
            'ffc_number'       => $agent->ffc_number ?? null,
            'website'          => $agent->website ?? null,
            'agent_photo_url'  => $agent->agent_photo_path ? asset('storage/' . $agent->agent_photo_path) : null,
            'logo_url'         => $agency && $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
            'email_disclaimer' => $request->input('email_disclaimer', $agency->email_disclaimer ?? null),
            'popi_url'         => $request->input('popi_url', $agency->popi_url ?? null),
            'agency_name'      => $agency->name ?? 'Home Finders Coastal',
        ];

        $html = view('emails.signatures.partials.agent-footer', compact('agentFooter'))->render();

        $shell = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body { font-family: Arial, Helvetica, sans-serif; margin: 8px; font-size: 13px; color: #333; }'
            . '</style></head><body>' . $html . '</body></html>';

        return response($shell)->header('Content-Type', 'text/html');
    }

    // ── Dashboard Settings Mode ────────────────────────────────────────

    public function updateDashboardMode(Request $request)
    {
        $request->validate([
            'dashboard_settings_mode' => 'required|in:user,agency',
        ]);

        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $agency->update(['dashboard_settings_mode' => $request->dashboard_settings_mode]);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'dashboard'])
            ->with('success', 'Dashboard settings mode updated to "' . ucfirst($request->dashboard_settings_mode) . '".');
    }

    // ── Split Branches toggle ──────────────────────────────────────────

    public function updateSplitBranches(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $request->validate([
            'split_branches_enabled' => ['nullable', 'boolean'],
        ]);

        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $agency->update([
            'split_branches_enabled' => $request->boolean('split_branches_enabled'),
        ]);

        $state = $agency->split_branches_enabled ? 'ON' : 'OFF';
        return redirect()->route('corex.settings', ['tab' => 'agency'])
            ->with('success', "Split Branches turned {$state}.");
    }

    public function updateAgencyDashboardSettings(Request $request)
    {
        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $boolFields = [
            'idle_alerts_enabled', 'doc_reminders_enabled', 'lease_expiry_reminders',
            'fica_reminders', 'ffc_reminders', 'task_due_reminders', 'overdue_daily_digest',
            'weekend_visible', 'notify_in_app', 'notify_email',
        ];

        $data = $request->only([
            'idle_alerts_enabled', 'idle_threshold_days', 'idle_alert_day', 'idle_alert_time',
            'doc_reminders_enabled', 'lease_expiry_reminders', 'fica_reminders', 'ffc_reminders',
            'task_due_reminders', 'overdue_daily_digest', 'digest_time',
            'default_calendar_view', 'weekend_visible', 'notify_in_app', 'notify_email',
        ]);

        foreach ($boolFields as $bf) {
            $data[$bf] = $request->boolean($bf);
        }

        AgencyDashboardSetting::updateOrCreate(
            ['agency_id' => $agency->id],
            $data
        );

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'dashboard'])
            ->with('success', 'Agency dashboard settings saved.');
    }
}
