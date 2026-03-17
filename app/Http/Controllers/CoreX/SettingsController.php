<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
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
        $data['agency'] = Agency::where('slug', 'hfc-coastal')->first();

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

    public function updateMatchesEnabled(Request $request)
    {
        $enabled = $request->boolean('matches_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => $enabled ? 1 : 0]);
        return back()->with('success', 'Core Matches setting updated.')->with('tab', 'feature')->with('fsec', 'matches');
    }

    public function updateMatchesShowOnProperties(Request $request)
    {
        $enabled = $request->boolean('matches_show_on_properties');
        PerformanceSetting::updateOrCreate(['key' => 'matches_show_on_properties'], ['value' => $enabled ? 1 : 0]);
        return back()->with('success', 'Setting updated.')->with('tab', 'feature')->with('fsec', 'matches');
    }

    public function updateMatchesWaMessage(Request $request)
    {
        $message = substr($request->input('matches_wa_message', ''), 0, 1000);
        PerformanceSetting::updateOrCreate(['key' => 'matches_wa_message'], ['value' => $message]);
        return back()->with('success', 'WhatsApp message template saved.')->with('tab', 'feature')->with('fsec', 'matches');
    }

    public function generateApiToken(Request $request)
    {
        $plaintext = Str::random(64);

        $request->user()->update([
            'api_token' => hash('sha256', $plaintext),
        ]);

        return response()->json(['token' => $plaintext]);
    }

    // ── Agency Company Settings ───────────────────────────────────────────

    public function updateAgency(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $agency = Agency::where('slug', 'hfc-coastal')->firstOrFail();

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
}
