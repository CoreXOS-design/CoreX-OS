<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DevSetting;
use Illuminate\Http\Request;

class DevSettingsController extends Controller
{
    public function index()
    {
        return view('admin.dev-settings.index', [
            'complianceChecksDisabled' => DevSetting::bool('compliance_checks_disabled'),
        ]);
    }

    public function update(Request $request)
    {
        DevSetting::set(
            'compliance_checks_disabled',
            $request->boolean('compliance_checks_disabled') ? '1' : '0'
        );

        return redirect()->route('admin.dev-settings.index')
            ->with('success', 'Dev settings updated.');
    }
}
