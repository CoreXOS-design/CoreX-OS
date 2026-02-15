<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerformanceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PerformanceSettingsController extends Controller
{
    private function ensureAdmin(): void
    {
        $u = \Illuminate\Support\Facades\Auth::user();
        if (!$u || (!($u->is_admin ?? false) && (($u->role ?? '') !== 'admin'))) {
            abort(403);
        }
    }


    public function edit()
    {
        $this->ensureAdmin();
        $vatRate = (float) PerformanceSetting::get('vat_rate', 15);
        $listingsPerSale = (float) PerformanceSetting::get('listings_per_sale', 5);

        // Company settings (global)
        $companyName = (string) PerformanceSetting::get('company_name', 'Home Finders Coastal');
        $companyAddress = (string) PerformanceSetting::get('company_address', 'The Emporium Shop 5, Shelly Beach, Margate');
        $companyTel = (string) PerformanceSetting::get('company_tel', '(039) 315 0857');
        $companyFfc = (string) PerformanceSetting::get('company_ffc', '2023116041');
        $companyLogoUrl = (string) PerformanceSetting::get('company_logo_url', '');

        return view('admin.performance-settings', [
            'vatRate' => $vatRate,
            'listingsPerSale' => $listingsPerSale,
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'companyTel' => $companyTel,
            'companyFfc' => $companyFfc,
            'companyLogoUrl' => $companyLogoUrl,
        ]);
    }

    public function update(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            // Company settings
            'company_name' => ['nullable','string','max:255'],
            'company_address' => ['nullable','string','max:255'],
            'company_tel' => ['nullable','string','max:100'],
            'company_ffc' => ['nullable','string','max:100'],

            // Logo upload (stored as URL in performance_settings: company_logo_url)
            'company_logo' => ['nullable','image','max:2048'], // 2MB
            'clear_company_logo' => ['nullable','in:0,1'],

            // Existing performance settings
            'vat_rate' => ['required','numeric','min:0','max:100'],
            'listings_per_sale' => ['required','numeric','min:0.01','max:1000'],
        ]);

        // Persist company text settings
        $companyKeys = ['company_name','company_address','company_tel','company_ffc'];
        foreach ($companyKeys as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                PerformanceSetting::updateOrCreate(['key' => $k], ['value' => ($v === null ? '' : (string)$v)]);
            }
        }

        // Handle logo clear
        $clear = isset($data['clear_company_logo']) && (string)$data['clear_company_logo'] === '1';
        if ($clear) {
            PerformanceSetting::updateOrCreate(['key' => 'company_logo_url'], ['value' => '']);
        }

        // Handle logo upload (store on public disk)
        if ($request->hasFile('company_logo') && $request->file('company_logo')->isValid()) {
            $path = $request->file('company_logo')->store('company', 'public');
            $url = Storage::url($path); // e.g. /storage/company/xxxx.png
            PerformanceSetting::updateOrCreate(['key' => 'company_logo_url'], ['value' => (string)$url]);
        }

        // Performance settings
        PerformanceSetting::updateOrCreate(['key' => 'vat_rate'], ['value' => (string)$data['vat_rate']]);
        PerformanceSetting::updateOrCreate(['key' => 'listings_per_sale'], ['value' => (string)$data['listings_per_sale']]);

        return redirect()->back()->with('status', 'Performance settings updated.');
    }
}
