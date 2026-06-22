<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactTag;
use App\Models\ContactType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sub-tags (AT-79): agency-scoped custom labels nested under one of the four
 * fixed parent contact types. Every sub-tag MUST belong to a canonical parent.
 */
class ContactTagController extends Controller
{
    /** Validation rule: contact_type_id must be one of the 4 canonical parents. */
    private function parentRule(): array
    {
        return [
            'required',
            Rule::exists('contact_types', 'id')->whereIn('esign_role', array_keys(ContactType::CANONICAL)),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'contact_type_id' => $this->parentRule(),
            'color'           => 'nullable|string|max:7',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        $data['color']      = $data['color'] ?? '#6366f1';
        $data['sort_order'] = $data['sort_order'] ?? 0;

        ContactTag::create($data);

        return $this->redirect('Sub-tag added.');
    }

    public function update(Request $request, ContactTag $contactTag)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'contact_type_id' => $this->parentRule(),
            'color'           => 'nullable|string|max:7',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

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
