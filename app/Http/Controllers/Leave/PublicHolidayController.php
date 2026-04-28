<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\PublicHoliday;
use Illuminate\Http\Request;

class PublicHolidayController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->query('year', (int) now()->format('Y'));
        $years = PublicHoliday::selectRaw('DISTINCT applies_to_year')
            ->orderByDesc('applies_to_year')->pluck('applies_to_year');

        $holidays = PublicHoliday::forYear($year)->forCountry('ZA')
            ->orderBy('holiday_date')
            ->get();

        return view('payroll.leave.public-holidays.index', compact('holidays', 'year', 'years'));
    }

    public function create()
    {
        $holiday = new PublicHoliday();
        return view('payroll.leave.public-holidays.create', compact('holiday'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'holiday_date'    => 'required|date',
            'name'            => 'required|string|max:100',
            'country_code'    => 'required|string|size:2',
            'is_movable'      => 'boolean',
            'applies_to_year' => 'required|integer|min:2020|max:2099',
        ]);

        $validated['is_movable'] = $validated['is_movable'] ?? false;

        PublicHoliday::create($validated);

        return redirect()->route('payroll.leave.public-holidays.index', ['year' => $validated['applies_to_year']])
            ->with('success', "Holiday \"{$validated['name']}\" added.");
    }

    public function edit($id)
    {
        $holiday = PublicHoliday::findOrFail($id);
        return view('payroll.leave.public-holidays.edit', compact('holiday'));
    }

    public function update(Request $request, $id)
    {
        $holiday = PublicHoliday::findOrFail($id);

        $validated = $request->validate([
            'holiday_date'    => 'required|date',
            'name'            => 'required|string|max:100',
            'country_code'    => 'required|string|size:2',
            'is_movable'      => 'boolean',
            'applies_to_year' => 'required|integer|min:2020|max:2099',
        ]);

        $validated['is_movable'] = $validated['is_movable'] ?? false;

        $holiday->update($validated);

        return redirect()->route('payroll.leave.public-holidays.index', ['year' => $validated['applies_to_year']])
            ->with('success', "Holiday \"{$validated['name']}\" updated.");
    }

    public function destroy($id)
    {
        $holiday = PublicHoliday::findOrFail($id);
        $year = $holiday->applies_to_year;
        $holiday->delete();

        return redirect()->route('payroll.leave.public-holidays.index', ['year' => $year])
            ->with('success', 'Holiday deleted.');
    }
}
