<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUpdate;
use App\Services\SystemUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * System Updates — the authoring surface (System Developer → System Updates).
 *
 * Spec: .ai/specs/system-updates.md
 *
 * ── On permissions (spec §10) ────────────────────────────────────────────────
 * The route group is `owner_only` and every action ALSO calls guardOwner().
 * There is deliberately NO grantable permission key in corex-permissions.php, and
 * that satisfies non-negotiable #5 rather than skipping it — same reasoning as
 * demo-access-control.md §8.
 *
 * A permission key is GRANTABLE via the Role Manager. This page broadcasts a
 * full-screen modal, carrying arbitrary text and an arbitrary link, to EVERY USER
 * OF EVERY AGENCY in CoreX. One mis-click in the Role Manager and an agency admin
 * is interrupting every one of our other tenants' agents. `owner_only` has no
 * delegation path; a permission key does. The stronger gate is the correct gate.
 *
 * DO NOT "fix" this by adding a permission key.
 */
class SystemUpdateController extends Controller
{
    public function __construct(private readonly SystemUpdateService $service)
    {
    }

    /** Belt and braces behind the owner_only middleware. */
    private function guardOwner(Request $request): void
    {
        abort_unless($request->user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    // ── Read ────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $this->guardOwner($request);

        $showArchived = $request->boolean('archived');

        $updates = SystemUpdate::with('author')
            ->when($showArchived, fn ($q) => $q->onlyTrashed())
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->paginate(25)
            ->withQueryString();

        // Adoption per row. Runs on an owner-only screen over a paginated set,
        // never on the hot path.
        $adoption = [];
        foreach ($updates as $update) {
            $adoption[$update->id] = [
                'seen'  => $this->service->seenCount($update),
                'total' => $this->service->audienceUserCount($update),
            ];
        }

        return view('admin.system-updates.index', compact('updates', 'adoption', 'showArchived'));
    }

    public function show(Request $request, int $update): View
    {
        $this->guardOwner($request);

        $systemUpdate = SystemUpdate::withTrashed()->with('author')->findOrFail($update);

        $viewers = $systemUpdate->views()
            ->with('user:id,name,email')
            ->orderByDesc('dismissed_at')
            ->paginate(50);

        return view('admin.system-updates.show', [
            'update'  => $systemUpdate,
            'viewers' => $viewers,
            'seen'    => $this->service->seenCount($systemUpdate),
            'total'   => $this->service->audienceUserCount($systemUpdate),
        ]);
    }

    public function create(Request $request): View
    {
        $this->guardOwner($request);

        return view('admin.system-updates.create', ['update' => new SystemUpdate(['type' => 'feature'])]);
    }

    public function edit(Request $request, int $update): View
    {
        $this->guardOwner($request);

        return view('admin.system-updates.edit', [
            'update' => SystemUpdate::withTrashed()->findOrFail($update),
        ]);
    }

    /** Renders the REAL modal component so the owner sees exactly what a user will. */
    public function preview(Request $request, int $update): View
    {
        $this->guardOwner($request);

        return view('admin.system-updates.preview', [
            'update' => SystemUpdate::withTrashed()->findOrFail($update),
        ]);
    }

    // ── Write ───────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->guardOwner($request);

        $data = $this->validated($request);
        $data['created_by_user_id'] = $request->user()->id;
        $data['status']             = SystemUpdate::STATUS_DRAFT;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('system-updates', 'public');
        }

        $update = SystemUpdate::create($data);

        // "Publish now" from the create form — one action, not two round trips.
        if ($request->boolean('publish_now')) {
            $this->markPublished($update);

            return redirect()->route('admin.system-updates.index')
                ->with('success', 'Update published. Every CoreX user will see it on their next page load.');
        }

        return redirect()->route('admin.system-updates.edit', $update)
            ->with('success', 'Draft saved. Nobody can see it until you publish.');
    }

    public function update(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        $systemUpdate = SystemUpdate::withTrashed()->findOrFail($update);
        $data         = $this->validated($request);

        if ($request->boolean('remove_image')) {
            $this->deleteImage($systemUpdate);
            $data['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            $this->deleteImage($systemUpdate);
            $data['image_path'] = $request->file('image')->store('system-updates', 'public');
        }

        $systemUpdate->update($data);

        // Editing a published update does NOT re-show it to people who already
        // dismissed it — a typo fix must not re-interrupt everyone. Use
        // "Re-notify everyone" for that (spec §7.4).
        return redirect()->route('admin.system-updates.edit', $systemUpdate)
            ->with('success', 'Saved. People who already closed this will not see it again — use “Re-notify everyone” if you want them to.');
    }

    public function publish(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        $systemUpdate = SystemUpdate::withTrashed()->findOrFail($update);

        // Idempotent — republishing an already-live update is a no-op, not an error.
        if ($systemUpdate->isPublished()) {
            return back()->with('success', 'That update is already published.');
        }

        $this->markPublished($systemUpdate);

        return back()->with('success', 'Published. Every CoreX user will see it on their next page load.');
    }

    public function unpublish(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        $systemUpdate = SystemUpdate::withTrashed()->findOrFail($update);
        $systemUpdate->update([
            'status'       => SystemUpdate::STATUS_DRAFT,
            'published_at' => null,
        ]);

        return back()->with('success', 'Unpublished. It is a draft again and nobody will see it.');
    }

    /**
     * Re-show an update to everyone, including people who already closed it.
     *
     * A watermark, NOT a delete: every system_update_views row survives, so the
     * record of who saw the original is permanent (spec §7.4).
     */
    public function renotify(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        $systemUpdate = SystemUpdate::withTrashed()->findOrFail($update);

        if (! $systemUpdate->isPublished()) {
            return back()->with('success', 'Publish the update first — there is nothing to re-notify yet.');
        }

        $systemUpdate->update(['notify_reset_at' => now()]);

        return back()->with('success', 'Every CoreX user will see this update again on their next page load.');
    }

    /** "Delete" archives. The row is never removed (non-negotiable #1). */
    public function destroy(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        SystemUpdate::findOrFail($update)->delete();

        return redirect()->route('admin.system-updates.index')
            ->with('success', 'Archived. It has stopped showing to users and can be restored at any time.');
    }

    public function restore(Request $request, int $update): RedirectResponse
    {
        $this->guardOwner($request);

        SystemUpdate::onlyTrashed()->findOrFail($update)->restore();

        return redirect()->route('admin.system-updates.index')
            ->with('success', 'Restored.');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function markPublished(SystemUpdate $update): void
    {
        $update->update([
            'status'       => SystemUpdate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function deleteImage(SystemUpdate $update): void
    {
        if (filled($update->image_path)) {
            Storage::disk('public')->delete($update->image_path);
        }
    }

    /**
     * Validation — the whole input space (spec §9.1, §9.2).
     *
     * Every rule here is also a prevent-or-absorb decision made at spec time:
     * required fields are PREVENTED from being empty with a message a
     * non-technical user understands; optional fields are ABSORBED when empty.
     *
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        // Trim first, so a whitespace-only title is empty rather than "valid"
        // (BUILD_STANDARD §2 — whitespace).
        $request->merge([
            'title'      => is_string($request->input('title')) ? trim($request->input('title')) : $request->input('title'),
            'body'       => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body'),
            'link_url'   => is_string($request->input('link_url')) ? trim($request->input('link_url')) : $request->input('link_url'),
            'link_label' => is_string($request->input('link_label')) ? trim($request->input('link_label')) : $request->input('link_label'),
        ]);

        $validated = $request->validate([
            'title'      => ['required', 'string', 'max:160'],
            'body'       => ['required', 'string', 'max:5000'],
            'type'       => ['required', Rule::in(array_keys(config('system-updates.types', [])))],
            'link_url'   => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (blank($value)) {
                    return;
                }
                // An internal path, or an absolute http(s) URL. Nothing else.
                // javascript: and data: are rejected outright — this link is shown
                // to every user in CoreX and is an XSS vector if unvalidated.
                $isInternalPath = str_starts_with($value, '/') && ! str_starts_with($value, '//');
                $isHttpUrl      = (bool) preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false;

                if (! $isInternalPath && ! $isHttpUrl) {
                    $fail('The link must be a CoreX page starting with “/” (for example /corex/properties) or a full web address starting with http:// or https://');
                }
            }],
            'link_label' => ['nullable', 'string', 'max:60'],
            'image'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
        ], [
            'title.required' => 'Give the update a title so users know what changed.',
            'body.required'  => 'Describe what changed — this is what users will read.',
            'title.max'      => 'Keep the title under 160 characters so it fits on one line.',
            'body.max'       => 'Keep the description under 5 000 characters.',
            'image.max'      => 'That image is too large — please use one under 4 MB.',
            'image.mimes'    => 'Use a JPG, PNG, WEBP or GIF image.',
            'image.image'    => 'That file is not an image.',
        ]);

        // `image` is handled separately (it is a file, not a column).
        unset($validated['image']);

        return $validated;
    }
}
