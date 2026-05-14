<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\User;
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

        return view('admin.company-settings.index', compact(
            'agencies', 'agency', 'agents', 'branches', 'vatRate', 'listingsPerSale'
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
            'fic_no'                => ['nullable', 'string', 'max:255'],
            'email_disclaimer'      => ['nullable', 'string', 'max:2000'],
            'popi_url'              => ['nullable', 'string', 'max:500'],
            'sidebar_color'         => ['nullable', 'string', 'max:20'],
            'icon_color'            => ['nullable', 'string', 'max:20'],
            'default_color'         => ['nullable', 'string', 'max:20'],
            'button_color'          => ['nullable', 'string', 'max:20'],
            'logo'                  => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo'           => ['nullable', 'boolean'],
            // 2026-05-14 hotfix — agency-scoped WhatsApp launch modes.
            'whatsapp_launch_mode_agent'  => ['nullable', 'in:whatsapp_app,whatsapp_web'],
            'whatsapp_launch_mode_seller' => ['nullable', 'in:whatsapp_app,whatsapp_web'],
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

        return redirect()->route('admin.company-settings', ['agency' => $agency->id])
            ->with('success', 'Company settings updated.');
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
