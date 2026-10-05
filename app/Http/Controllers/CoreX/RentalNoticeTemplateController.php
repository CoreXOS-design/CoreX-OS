<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalNoticeTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. Agency-owned
 * breach-notice / notice-to-vacate templates. Full CRUD + archive/restore,
 * search/sort/filter/pagination per BUILD_STANDARD §1a, agency-scoped via
 * BelongsToAgency + AgencyScope on the model. Mirrors
 * RentalFaultTypeController's shape exactly.
 */
class RentalNoticeTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalNoticeTemplate::query();

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($type = $request->get('notice_type')) {
            $query->where('notice_type', $type);
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'name');
        $allowedSorts = ['name', 'notice_type', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }
        $query->orderBy($sort);

        $templates = $query->paginate(25)->withQueryString();

        return view('corex.rental-notice-templates.index', compact('templates', 'status', 'sort'));
    }

    public function create(): View
    {
        return view('corex.rental-notice-templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $template = RentalNoticeTemplate::create($data + ['created_by_user_id' => $request->user()->id]);

        return redirect()->route('corex.rental-notice-templates.index')->with('success', "Template '{$template->name}' created.");
    }

    public function edit(RentalNoticeTemplate $rentalNoticeTemplate): View
    {
        return view('corex.rental-notice-templates.edit', ['template' => $rentalNoticeTemplate]);
    }

    public function update(Request $request, RentalNoticeTemplate $rentalNoticeTemplate): RedirectResponse
    {
        $rentalNoticeTemplate->update($this->validated($request));

        return redirect()->route('corex.rental-notice-templates.index')->with('success', "Template '{$rentalNoticeTemplate->name}' updated.");
    }

    public function archive(RentalNoticeTemplate $rentalNoticeTemplate): RedirectResponse
    {
        $rentalNoticeTemplate->delete();

        return back()->with('success', "Template '{$rentalNoticeTemplate->name}' archived.");
    }

    public function restore(int $id): RedirectResponse
    {
        $template = RentalNoticeTemplate::onlyTrashed()->findOrFail($id);
        $template->restore();

        return back()->with('success', "Template '{$template->name}' restored.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'notice_type' => ['required', 'in:' . implode(',', RentalNoticeTemplate::TYPES)],
            'body_html' => ['required', 'string', 'max:50000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
