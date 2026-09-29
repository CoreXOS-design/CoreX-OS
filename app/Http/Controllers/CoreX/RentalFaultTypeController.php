<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * .ai/specs/rentals-faults-work-orders.md §2/§8.1 — the agency's fault
 * catalogue settings screen. Full CRUD (create/edit/archive/restore),
 * search/sort/filter/pagination per BUILD_STANDARD §1b, agency-scoped via
 * BelongsToAgency + AgencyScope on the model.
 */
class RentalFaultTypeController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalFaultType::query();

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        if ($urgency = $request->get('urgency')) {
            $query->where('urgency', $urgency);
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'sort_order');
        $allowedSorts = ['sort_order', 'name', 'category', 'urgency'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'sort_order';
        }
        $query->orderBy($sort)->orderBy('id');

        $faultTypes = $query->with('documents')->paginate(25)->withQueryString();

        $categories = RentalFaultType::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('corex.rental-fault-types.index', compact('faultTypes', 'categories', 'status', 'sort'));
    }

    public function create(): View
    {
        return view('corex.rental-fault-types.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $faultType = RentalFaultType::create($data + [
            'is_default' => false,
            'sort_order' => (int) (RentalFaultType::max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        $this->storeDocuments($request, $faultType);

        return redirect()->route('corex.rental-fault-types.index')->with('success', "Fault type '{$faultType->name}' added.");
    }

    public function edit(RentalFaultType $rentalFaultType): View
    {
        return view('corex.rental-fault-types.edit', ['faultType' => $rentalFaultType]);
    }

    public function update(Request $request, RentalFaultType $rentalFaultType): RedirectResponse
    {
        $data = $this->validated($request);
        $rentalFaultType->update($data);

        $this->storeDocuments($request, $rentalFaultType);

        return redirect()->route('corex.rental-fault-types.index')->with('success', "Fault type '{$rentalFaultType->name}' updated.");
    }

    public function archive(RentalFaultType $rentalFaultType): RedirectResponse
    {
        $rentalFaultType->archive();

        return back()->with('success', "Fault type '{$rentalFaultType->name}' archived.");
    }

    public function restore(int $id): RedirectResponse
    {
        $rentalFaultType = RentalFaultType::onlyTrashed()->findOrFail($id);
        $rentalFaultType->restoreRecord();

        return back()->with('success', "Fault type '{$rentalFaultType->name}' restored.");
    }

    public function storeDocument(Request $request, RentalFaultType $rentalFaultType): RedirectResponse
    {
        $this->storeDocuments($request, $rentalFaultType, single: true);

        return back()->with('success', 'Document added.');
    }

    public function destroyDocument(RentalFaultType $rentalFaultType, RentalFaultTypeDocument $document): RedirectResponse
    {
        abort_unless($document->rental_fault_type_id === $rentalFaultType->id, 404);
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    public function downloadDocument(RentalFaultType $rentalFaultType, RentalFaultTypeDocument $document)
    {
        abort_unless($document->rental_fault_type_id === $rentalFaultType->id, 404);
        abort_if($document->storage_path === null, 404);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:100'],
            'urgency' => ['required', 'in:' . implode(',', [
                RentalFaultType::URGENCY_ROUTINE,
                RentalFaultType::URGENCY_URGENT,
                RentalFaultType::URGENCY_EMERGENCY,
            ])],
            'first_aid_steps' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }

    private function storeDocuments(Request $request, RentalFaultType $faultType, bool $single = false): void
    {
        $user = $request->user();

        if ($request->hasFile('image')) {
            // Fault-type images are agency-level catalogue content, not tied to any
            // property — PropertyImageStorer::store() requires a property id and
            // resizes into a property's own gallery path, so it does not fit here.
            // Stored to the public disk directly, same as any other agency-owned,
            // tenant-visible catalogue asset (no property to key it to).
            $path = $request->file('image')->store("rental-fault-type-images/{$faultType->id}", 'public');
            $faultType->documents()->create([
                'document_type' => RentalFaultTypeDocument::TYPE_IMAGE,
                'storage_path' => $path,
                'caption' => $request->input('caption'),
                'sort_order' => (int) ($faultType->documents()->max('sort_order') ?? 0) + 1,
                'uploaded_by_user_id' => $user->id,
            ]);
        }

        if ($request->hasFile('document_file')) {
            $file = $request->file('document_file');
            $isPdf = strtolower($file->getClientOriginalExtension()) === 'pdf';
            $path = $file->store("rental-fault-type-documents/{$faultType->id}", 'local');
            $faultType->documents()->create([
                'document_type' => $isPdf ? RentalFaultTypeDocument::TYPE_PDF : RentalFaultTypeDocument::TYPE_DOCUMENT,
                'storage_path' => $path,
                'caption' => $request->input('caption'),
                'sort_order' => (int) ($faultType->documents()->max('sort_order') ?? 0) + 1,
                'uploaded_by_user_id' => $user->id,
            ]);
        }

        if ($url = trim((string) $request->input('video_url', ''))) {
            $faultType->documents()->create([
                'document_type' => RentalFaultTypeDocument::TYPE_VIDEO_LINK,
                'external_url' => $url,
                'caption' => $request->input('caption'),
                'sort_order' => (int) ($faultType->documents()->max('sort_order') ?? 0) + 1,
                'uploaded_by_user_id' => $user->id,
            ]);
        }
    }
}
