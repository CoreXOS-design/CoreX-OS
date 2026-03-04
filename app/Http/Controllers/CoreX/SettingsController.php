<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Designation;
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
            'activeTab' => $request->get('tab', 'agency'),
        ];

        // User Settings tab: Designations
        $data['designations'] = $user?->hasPermission('manage_designations')
            ? Designation::orderBy('sort_order')->orderBy('name')->get()
            : collect([]);

        // Feature Settings tab: Docuperfect
        $data['docTypes']    = DocumentType::orderBy('sort_order')->orderBy('name')->get();
        $data['namedFields'] = NamedField::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Rentals
        $data['rentalDocTypes']         = RentalDocumentType::orderBy('sort_order')->get();
        $data['rentalReminderSettings'] = RentalReminderSetting::current();

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

    public function generateApiToken(Request $request)
    {
        $plaintext = Str::random(64);

        $request->user()->update([
            'api_token' => hash('sha256', $plaintext),
        ]);

        return response()->json(['token' => $plaintext]);
    }
}
