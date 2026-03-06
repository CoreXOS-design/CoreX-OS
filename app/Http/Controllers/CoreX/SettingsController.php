<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\AgentSocialAccount;
use App\Models\ContactType;
use App\Models\Designation;
use App\Models\PropertySettingItem;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\NamedField;
use App\Models\PerformanceSetting;
use App\Models\Rental\RentalDocumentType;
use App\Models\Rental\RentalReminderSetting;
use Illuminate\Http\Request;
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
        $data['docTypes']    = DocumentType::orderBy('sort_order')->orderBy('name')->get();
        $data['namedFields'] = NamedField::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Rentals
        $data['rentalDocTypes']         = RentalDocumentType::orderBy('sort_order')->get();
        $data['rentalReminderSettings'] = RentalReminderSetting::current();

        // Feature Settings tab: Contacts
        $data['contactTypes'] = ContactType::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Properties
        $data['propCategories']   = PropertySettingItem::group('category')->get();
        $data['propTypes']        = PropertySettingItem::group('property_type')->get();
        $data['propStatuses']     = PropertySettingItem::group('property_status')->get();
        $data['propMandateTypes'] = PropertySettingItem::group('mandate_type')->get();

        // Feature Settings tab: Properties — marketing toggle
        $data['marketingEnabled'] = (bool) PerformanceSetting::get('marketing_enabled', 1);

        // Agency Settings tab: Company / Performance Settings
        if ($user?->hasPermission('manage_performance_settings')) {
            $data['vatRate']         = (float)  PerformanceSetting::get('vat_rate', 15);
            $data['listingsPerSale'] = (float)  PerformanceSetting::get('listings_per_sale', 5);
            $data['companyName']     = (string) PerformanceSetting::get('company_name', '');
            $data['companyAddress']  = (string) PerformanceSetting::get('company_address', '');
            $data['companyTel']      = (string) PerformanceSetting::get('company_tel', '');
            $data['companyFfc']      = (string) PerformanceSetting::get('company_ffc', '');
            $data['companyLogoUrl']  = (string) PerformanceSetting::get('company_logo_url', '');
        }

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
        PropertySettingItem::create($data);
        return back()->with('success', 'Item added.')->with('tab', 'feature')->with('fsec', 'properties');
    }

    public function updatePropertySettingItem(Request $request, PropertySettingItem $item)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $item->update($data);
        return back()->with('success', 'Item updated.')->with('tab', 'feature')->with('fsec', 'properties');
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
            return back()->with('error', 'Invalid group.');
        }

        $enabledIds = array_map('intval', $request->input('enabled_ids', []));

        PropertySettingItem::where('group', $group)
            ->where('is_default', true)
            ->get()
            ->each(fn($item) => $item->update(['active' => in_array($item->id, $enabledIds)]));

        return back()->with('success', 'Updated successfully.')
            ->with('tab', 'feature')
            ->with('fsec', 'properties');
    }

    public function togglePropertySettingItem(PropertySettingItem $item)
    {
        $item->update(['active' => ! $item->active]);
        return back()->with('success', 'Item updated.')->with('tab', 'feature')->with('fsec', 'properties');
    }

    public function destroyPropertySettingItem(PropertySettingItem $item)
    {
        if ($item->is_default) {
            return back()->with('error', 'Default items cannot be deleted — use the toggle to disable them instead.');
        }
        $item->delete();
        return back()->with('success', 'Item deleted.')->with('tab', 'feature')->with('fsec', 'properties');
    }

    public function updateMarketingEnabled(Request $request)
    {
        $enabled = $request->boolean('marketing_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'marketing_enabled'], ['value' => $enabled ? 1 : 0]);
        return back()->with('success', 'Marketing setting updated.')->with('tab', 'feature')->with('fsec', 'properties');
    }

    public function generateApiToken(Request $request)
    {
        $plaintext = Str::random(64);

        $request->user()->update([
            'api_token' => hash('sha256', $plaintext),
        ]);

        return response()->json(['token' => $plaintext]);
    }
}
