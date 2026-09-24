<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalChecklistTemplateItem;
use App\Models\RentalChecklistTemplateSection;
use App\Services\RentalApplications\RentalApplicationChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-430 §3.3 — "Application checklist" settings screen. Agency admins
 * create, rename, reorder and archive sections and items. Full CRUD floor
 * (BUILD_STANDARD §1a): create/read/update/archive/restore, search, sort,
 * a stated default, and a real empty state — same shape as
 * RentalApplicationDeclineReasonTemplateController, extended to two levels
 * (sections containing items).
 *
 * Reorder uses swap-with-neighbour (move up/down) rather than drag-and-drop
 * — same robustness, no JS library, consistent with every other
 * sort_order-backed list in this module.
 *
 * Derived items (is_derived=true — the three Lease-progress items Johan
 * ruled are system-derived, AT-430 Part D) can never be created through
 * this screen's forms (no derived_key field is ever exposed) and can never
 * be edited once seeded — updateItem() refuses on a derived item. They CAN
 * still be archived, same as any other item, if an agency wants the line
 * gone entirely.
 */
class RentalApplicationChecklistTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        RentalApplicationChecklistService::seedDefaultTemplateFor($agencyId);

        $status = $request->query('status', 'active');
        $status = in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';

        $sort = $request->query('sort', 'sort_order');
        $sort = in_array($sort, ['sort_order', 'name', 'created_at'], true) ? $sort : 'sort_order';
        $direction = $request->query('direction', 'asc');
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $itemStatus = fn ($q) => $status === 'archived' ? $q->onlyTrashed() : ($status === 'all' ? $q->withTrashed() : $q);

        $query = RentalChecklistTemplateSection::query()->where('agency_id', $agencyId);
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($sub) use ($q, $agencyId, $itemStatus) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhereHas('items', function ($iq) use ($q, $agencyId, $itemStatus) {
                        $itemStatus($iq)->where('agency_id', $agencyId)->where('name', 'like', "%{$q}%");
                    });
            });
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'name') {
            $query->orderBy('name');
        }

        $sections = $query->with(['items' => function ($iq) use ($itemStatus) {
            $itemStatus($iq)->orderBy('sort_order')->orderBy('id');
        }])->get();

        return view('corex.rental-applications.checklist-templates.index', compact('sections', 'status', 'sort', 'direction', 'q'));
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $validated = $request->validate(['name' => ['required', 'string', 'max:191']]);

        RentalChecklistTemplateSection::create([
            'agency_id' => $agencyId,
            'name' => $validated['name'],
            'sort_order' => RentalChecklistTemplateSection::nextSortOrderFor($agencyId),
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Checklist section added.');
    }

    public function updateSection(Request $request, RentalChecklistTemplateSection $section): RedirectResponse
    {
        $this->guardSectionAgency($request, $section);
        $validated = $request->validate(['name' => ['required', 'string', 'max:191']]);
        $section->update($validated);

        return back()->with('success', 'Checklist section updated.');
    }

    public function archiveSection(Request $request, RentalChecklistTemplateSection $section): RedirectResponse
    {
        $this->guardSectionAgency($request, $section);
        $section->delete();

        return back()->with('success', 'Checklist section archived.');
    }

    public function restoreSection(Request $request, int $section): RedirectResponse
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $row = RentalChecklistTemplateSection::withTrashed()->where('agency_id', $agencyId)->findOrFail($section);
        $row->restore();

        return back()->with('success', 'Checklist section restored.');
    }

    public function moveSection(Request $request, RentalChecklistTemplateSection $section, string $direction): RedirectResponse
    {
        $this->guardSectionAgency($request, $section);
        $this->swapSortOrder(
            RentalChecklistTemplateSection::where('agency_id', $section->agency_id),
            $section,
            $direction,
        );

        return back();
    }

    public function storeItem(Request $request, RentalChecklistTemplateSection $section): RedirectResponse
    {
        $this->guardSectionAgency($request, $section);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'note_required' => ['nullable', 'boolean'],
        ]);

        RentalChecklistTemplateItem::create([
            'agency_id' => $section->agency_id,
            'template_section_id' => $section->id,
            'name' => $validated['name'],
            'help_text' => $validated['help_text'] ?? null,
            'note_required' => (bool) ($validated['note_required'] ?? false),
            'is_derived' => false,
            'derived_key' => null,
            'sort_order' => RentalChecklistTemplateItem::nextSortOrderFor($section->id),
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Checklist item added.');
    }

    public function updateItem(Request $request, RentalChecklistTemplateItem $item): RedirectResponse
    {
        $this->guardItemAgency($request, $item);

        if ($item->is_derived) {
            return back()->withErrors(['item' => 'This item is derived from the lease and cannot be edited — archive it if this agency does not want it shown.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'note_required' => ['nullable', 'boolean'],
        ]);

        $item->update([
            'name' => $validated['name'],
            'help_text' => $validated['help_text'] ?? null,
            'note_required' => (bool) ($validated['note_required'] ?? false),
        ]);

        return back()->with('success', 'Checklist item updated.');
    }

    public function archiveItem(Request $request, RentalChecklistTemplateItem $item): RedirectResponse
    {
        $this->guardItemAgency($request, $item);
        $item->delete();

        return back()->with('success', 'Checklist item archived.');
    }

    public function restoreItem(Request $request, int $item): RedirectResponse
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $row = RentalChecklistTemplateItem::withTrashed()->where('agency_id', $agencyId)->findOrFail($item);
        $row->restore();

        return back()->with('success', 'Checklist item restored.');
    }

    public function moveItem(Request $request, RentalChecklistTemplateItem $item, string $direction): RedirectResponse
    {
        $this->guardItemAgency($request, $item);
        $this->swapSortOrder(
            RentalChecklistTemplateItem::where('template_section_id', $item->template_section_id),
            $item,
            $direction,
        );

        return back();
    }

    /** Route-model binding resolves by id alone — same defence-in-depth every other rental-applications settings controller in this file family uses. */
    private function guardSectionAgency(Request $request, RentalChecklistTemplateSection $section): void
    {
        abort_unless((int) $section->agency_id === (int) $request->user()->effectiveAgencyId(), 404);
    }

    private function guardItemAgency(Request $request, RentalChecklistTemplateItem $item): void
    {
        abort_unless((int) $item->agency_id === (int) $request->user()->effectiveAgencyId(), 404);
    }

    private function swapSortOrder($scopedQuery, $model, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $neighbour = (clone $scopedQuery)
            ->where('sort_order', $direction === 'up' ? '<' : '>', $model->sort_order)
            ->orderBy('sort_order', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (! $neighbour) {
            return;
        }

        $ownOrder = $model->sort_order;
        $model->update(['sort_order' => $neighbour->sort_order]);
        $neighbour->update(['sort_order' => $ownOrder]);
    }
}
