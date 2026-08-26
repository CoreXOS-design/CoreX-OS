<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Webinars — the system-owner admin surface.
 *
 * Spec: .ai/specs/webinar-registration.md §7
 *
 * ══ OWNER-ONLY. NO PERMISSION KEY. ══
 *
 * Same reasoning as Demo Access, which this sits beside: the registration list is RR
 * Technologies' own sales data — the companies evaluating CoreX, several of whom are
 * each other's competitors. A permission key is GRANTABLE, so one mis-click in the
 * Role Manager would hand an agency admin the list. `owner_only` has no delegation
 * path: isOwnerRole() or 403. That is the stronger gate, which is why using it
 * SATISFIES non-negotiable #5 rather than skirting it.
 *
 * Enforced at three layers: route middleware, the abort_unless in every action below,
 * and the sidebar's owner-gated block.
 */
class WebinarController extends Controller
{
    /** Layer 2 of 3. The route middleware is layer 1; the sidebar gate is layer 3. */
    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** GET /admin/dev-settings/webinars */
    public function index()
    {
        $this->assertOwner();

        $webinars = Webinar::withCount('registrations')
            ->orderByDesc('starts_at')
            ->paginate(25);

        return view('admin.webinars.index', compact('webinars'));
    }

    /** GET /admin/dev-settings/webinars/create */
    public function create()
    {
        $this->assertOwner();

        return view('admin.webinars.create');
    }

    /** POST /admin/dev-settings/webinars */
    public function store(Request $request)
    {
        $this->assertOwner();

        $data = $this->validated($request);

        $webinar = Webinar::create([
            'slug'                   => Webinar::uniqueSlug($data['slug'] ?: $data['title']),
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'starts_at'              => $data['starts_at'],
            'duration_minutes'       => $data['duration_minutes'] ?? null,
            'join_url'               => $data['join_url'] ?? null,
            'access_ends_days_after' => $data['access_ends_days_after'],
            'reminder_hours_before'  => $data['reminder_hours_before'],
            'created_by_user_id'     => Auth::id(),
        ]);

        return redirect()
            ->route('admin.webinars.show', $webinar)
            ->with('status', 'Webinar created. Send the registration link below to whoever builds the website page.');
    }

    /** GET /admin/dev-settings/webinars/{webinar} — the registration list. */
    public function show(Webinar $webinar)
    {
        $this->assertOwner();

        $webinar->load('creator');

        $registrations = $webinar->registrations()
            ->with('grant')
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.webinars.show', compact('webinar', 'registrations'));
    }

    /** GET /admin/dev-settings/webinars/{webinar}/edit */
    public function edit(Webinar $webinar)
    {
        $this->assertOwner();

        return view('admin.webinars.edit', compact('webinar'));
    }

    /**
     * PUT /admin/dev-settings/webinars/{webinar}
     *
     * Editing the date or the access window moves the deadline for people who have
     * ALREADY registered — their grants keep the deadline copied at issue, so the
     * two can diverge. That is the correct trade: retroactively shortening access
     * somebody was already promised would be worse. The edit screen says so.
     */
    public function update(Request $request, Webinar $webinar)
    {
        $this->assertOwner();

        $data = $this->validated($request);

        $webinar->update([
            'slug'                   => Webinar::uniqueSlug($data['slug'] ?: $data['title'], $webinar->id),
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'starts_at'              => $data['starts_at'],
            'duration_minutes'       => $data['duration_minutes'] ?? null,
            'join_url'               => $data['join_url'] ?? null,
            'access_ends_days_after' => $data['access_ends_days_after'],
            'reminder_hours_before'  => $data['reminder_hours_before'],
        ]);

        return redirect()
            ->route('admin.webinars.show', $webinar)
            ->with('status', 'Webinar updated.');
    }

    /**
     * DELETE /admin/dev-settings/webinars/{webinar}
     *
     * ARCHIVES. The row is never removed (non-negotiable #1) — registrations hang off
     * it and are the sales record of real people who signed up. Archiving closes
     * registration immediately.
     */
    public function destroy(Webinar $webinar)
    {
        $this->assertOwner();

        $webinar->forceFill(['archived_at' => Carbon::now()])->save();

        return redirect()
            ->route('admin.webinars.index')
            ->with('status', 'Webinar archived. The registration link is now closed — nobody else can sign up or be issued demo access.');
    }

    /** POST /admin/dev-settings/webinars/{webinar}/restore */
    public function restore(Webinar $webinar)
    {
        $this->assertOwner();

        $webinar->forceFill(['archived_at' => null])->save();

        return back()->with('status', 'Webinar restored.');
    }

    /**
     * GET /admin/dev-settings/webinars/{webinar}/export
     *
     * The registrations, as a CSV. This list is the ONLY record of who signed up —
     * webinar registrants deliberately do not become Contacts (spec §0 A5) — so an
     * export is how the sales follow-up actually happens.
     */
    public function export(Webinar $webinar): StreamedResponse
    {
        $this->assertOwner();

        $filename = 'webinar-' . $webinar->slug . '-registrations.csv';

        return response()->streamDownload(function () use ($webinar) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Name', 'Company', 'Email', 'Phone', 'Registered at', 'Demo access', 'Access ends', 'Reminder sent']);

            $webinar->registrations()
                ->with('grant')
                ->orderBy('created_at')
                ->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, [
                            $r->name,
                            $r->company_name,
                            $r->email,
                            $r->phone,
                            $r->created_at?->format('Y-m-d H:i'),
                            $r->accessStatusLabel(),
                            $r->grant?->expires_at?->format('Y-m-d H:i'),
                            $r->reminder_sent_at?->format('Y-m-d H:i'),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Shared validation.
     *
     * access_ends_days_after allows 0 — "access ends at the end of the webinar day"
     * is a legitimate choice, not a mistake.
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'slug'                   => ['nullable', 'string', 'max:255'],
            'description'            => ['nullable', 'string', 'max:5000'],
            'starts_at'              => ['required', 'date'],
            'duration_minutes'       => ['nullable', 'integer', 'min:5', 'max:1440'],
            'join_url'               => ['nullable', 'url', 'max:500'],
            'access_ends_days_after' => ['required', 'integer', 'min:0', 'max:365'],
            'reminder_hours_before'  => ['required', 'integer', 'min:1', 'max:336'],
        ], [
            'title.required'                  => 'Give the webinar a title — registrants see it in their confirmation email.',
            'starts_at.required'              => 'Set the date and time the webinar starts.',
            'join_url.url'                    => 'The joining link needs to be a full web address, starting with https://',
            'access_ends_days_after.required' => 'Say how long demo access should last.',
            'reminder_hours_before.required'  => 'Say how far ahead the reminder email should go out.',
        ]);
    }
}
