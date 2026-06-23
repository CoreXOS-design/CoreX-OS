<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactTag;
use App\Models\ContactType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sub-tags (AT-79): agency-scoped custom labels nested under one of the fixed
 * parent contact types (the 4 e-sign roles + Owner/Other). Every sub-tag MUST
 * belong to a parent. Names are de-duplicated case-insensitively per parent.
 */
class ContactTagController extends Controller
{
    /** Validation rule: contact_type_id must be one of the fixed parents. */
    private function parentRule(): array
    {
        return ['required', Rule::in(ContactType::parentIds())];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // One OR MORE names, comma- or newline-separated.
            'name'            => 'required|string|max:2000',
            'contact_type_id' => $this->parentRule(),
            'color'           => 'nullable|string|max:7',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        $parentId = (int) $data['contact_type_id'];
        $color    = $data['color'] ?? '#6366f1';
        $sort     = $data['sort_order'] ?? 0;

        // Split into multiple names; trim; drop blanks; dedupe within the input
        // (case-insensitive). Each capped at 100 chars.
        $names = collect(preg_split('/[,\r\n]+/', $data['name']))
            ->map(fn ($n) => mb_substr(trim($n), 0, 100))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->values();

        // Existing sub-tag names under this parent (agency-scoped), lower-cased.
        $existing = ContactTag::where('contact_type_id', $parentId)
            ->pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => true]);

        $created = 0;
        $skipped = 0;
        foreach ($names as $name) {
            if (isset($existing[mb_strtolower($name)])) {
                $skipped++;
                continue;
            }
            ContactTag::create([
                'contact_type_id' => $parentId,
                'name'            => $name,
                'color'           => $color,
                'sort_order'      => $sort,
            ]);
            $existing[mb_strtolower($name)] = true;
            $created++;
        }

        $msg = $created
            ? $created . ' sub-tag' . ($created !== 1 ? 's' : '') . ' added.'
                . ($skipped ? " {$skipped} already existed (skipped)." : '')
            : ($skipped ? 'No new sub-tags — all already existed.' : 'No sub-tags added.');

        return $this->redirect($msg);
    }

    public function update(Request $request, ContactTag $contactTag)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'contact_type_id' => $this->parentRule(),
            'color'           => 'nullable|string|max:7',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        $parentId = (int) $data['contact_type_id'];

        // Reject a rename that collides (case-insensitively) with another sub-tag
        // under the same parent.
        $duplicate = ContactTag::where('contact_type_id', $parentId)
            ->whereKeyNot($contactTag->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->exists();
        if ($duplicate) {
            return redirect()
                ->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])
                ->with('error', 'A sub-tag with that name already exists under this type.');
        }

        $contactTag->update($data);

        return $this->redirect('Sub-tag updated.');
    }

    public function destroy(ContactTag $contactTag)
    {
        $contactTag->contacts()->detach();
        $contactTag->delete();

        return $this->redirect('Sub-tag deleted.');
    }

    /** Delete several sub-tags at once. Agency-scoped via BelongsToAgency. */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'tag_ids'   => 'required|array|min:1',
            'tag_ids.*' => 'integer',
        ]);

        // Only this agency's tags are visible (global scope), so a tampered id
        // for another agency simply won't be found.
        $tags = ContactTag::whereIn('id', $data['tag_ids'])->get();
        foreach ($tags as $tag) {
            $tag->contacts()->detach();
            $tag->delete();
        }

        $count = $tags->count();

        return $this->redirect($count === 1 ? 'Sub-tag deleted.' : "{$count} sub-tags deleted.");
    }

    private function redirect(string $msg)
    {
        return redirect()
            ->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])
            ->with('success', $msg);
    }
}
