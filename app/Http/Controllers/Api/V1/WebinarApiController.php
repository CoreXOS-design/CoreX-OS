<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Webinars\WebinarJoinLinkSent;
use App\Http\Controllers\Controller;
use App\Mail\WebinarJoinLinkMail;
use App\Models\Role;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Webinars\WebinarRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public front door for webinar registration.
 *
 * Spec: .ai/specs/webinar-registration.md §4
 *
 * Called SERVER-TO-SERVER by the CoreX marketing website, authenticated by the site
 * connector (EnsureSiteConnector). The visitor's browser never reaches this
 * controller and never sees an access code — the website posts the form on their
 * behalf and shows its own thank-you page.
 *
 * SERVED BY PRIMARY ONLY; the middleware enforces it. Registrations and the demo
 * grants they issue are durable records, and the demo host's database is destroyed
 * every three days.
 */
class WebinarApiController extends Controller
{
    public function __construct(private readonly WebinarRegistrationService $service) {}

    /**
     * GET /api/v1/webinars/ping
     *
     * Reachability probe. Powers the "Test connection" button on the admin connector
     * card, and gives the website a cheap way to prove its token before anyone
     * depends on it at registration time.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok'      => true,
            'service' => 'corex-webinars',
            'time'    => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/webinars/{slug}
     *
     * So the website renders live details instead of hard-coding them and drifting
     * out of step with what CoreX will actually enforce.
     *
     * join_url IS DELIBERATELY ABSENT. It is earned by registering, not by reading
     * the page — a join link in a public response is a webinar anyone can walk into
     * without ever telling us who they are.
     */
    public function show(string $slug): JsonResponse
    {
        $webinar = Webinar::notArchived()->where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        return response()->json([
            'ok'      => true,
            'webinar' => [
                'slug'                => $webinar->slug,
                'title'               => $webinar->title,
                'description'         => $webinar->description,
                'starts_at'           => $webinar->starts_at->toIso8601String(),
                // The optional earlier cut-off, or null (§3.1a). registration_open
                // already carries the ENFORCEMENT; this is what lets the public page
                // EXPLAIN the closure ("registration closed on Friday at 17:00")
                // instead of simply refusing the form with no reason given.
                'registration_closes_at' => $webinar->registration_closes_at?->toIso8601String(),
                'duration_minutes'    => $webinar->duration_minutes,
                'registration_open'   => $webinar->isOpenForRegistration(),
                'demo_access_ends_at' => $webinar->demoAccessEndsAt()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/webinars/{slug}/register
     *
     * Creates the registration, issues demo access on the webinar's fixed deadline,
     * and queues ONE email carrying the confirmation, the join link, a calendar
     * invite and the credentials.
     *
     * THE RESPONSE NEVER CONTAINS THE ACCESS CODE, and the code is never logged. It
     * exists in the queued email and nowhere else.
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        $webinar = Webinar::notArchived()->where('slug', $slug)->first();

        // Closed, archived, past, or never existed — all one answer. Which of those
        // it was is not the website's business, and distinguishing them would let
        // anyone map our sales calendar by probing slugs.
        if (! $webinar || ! $webinar->isOpenForRegistration()) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'name'         => ['required', 'string', 'max:255'],
                'email'        => ['required', 'string', 'email', 'max:255'],
                'company_name' => ['required', 'string', 'max:255'],
                'phone'        => ['nullable', 'string', 'max:50'],
            ], [
                'name.required'         => 'Please enter your name.',
                'email.required'        => 'Please enter your email address.',
                'email.email'           => 'Please enter a valid email address.',
                'company_name.required' => 'Please enter your company name.',
            ]);
        } catch (ValidationException $e) {
            // Field-keyed, so the website can render each message against its own
            // input rather than dumping one banner at the top of the form.
            return response()->json([
                'ok'     => false,
                'errors' => $e->errors(),
            ], 422);
        }

        $result = $this->service->register(
            $webinar,
            $data,
            $request->ip(),
            $request->userAgent(),
        );

        // Log the fact, never the credential.
        Log::info('[webinars] registration accepted', [
            'webinar_id'      => $webinar->id,
            'registration_id' => $result['registration']->id,
            'throttled'       => $result['throttled'],
        ]);

        // A repeat submit inside the cooldown is NOT an error — the person did
        // nothing wrong, and telling them so would be confusing. They are registered;
        // the email they already have is still the one that works.
        return response()->json([
            'ok'         => true,
            'registered' => true,
            'throttled'  => $result['throttled'],
            'message'    => $result['throttled']
                ? 'You are already registered. Check your inbox for the confirmation email we sent you a few minutes ago.'
                : 'You are registered. Check your inbox for your joining details and demo access.',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // Admin API — the marketing website's own console.
    // Spec: .ai/specs/webinar-registration.md §4.3
    //
    // The CoreX-side screens at admin/dev-settings/webinars (§7.2) stay exactly
    // as they are; this is a second way in, for the person who runs the funnel
    // and should not need an owner login to hand out a registration link.
    //
    // SCOPE CAVEAT: these read registrant PII, and the site connector currently
    // has no scopes — so any valid site token reaches them, including the one
    // the website uses on its public page. See §4.3.
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/webinars?include_archived=false
     */
    public function index(Request $request): JsonResponse
    {
        $webinars = Webinar::query()
            ->when(
                ! filter_var($request->query('include_archived', 'false'), FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->whereNull('archived_at'),
            )
            // Soonest first: the webinar being worked on is nearly always the
            // next one out of the door.
            ->orderBy('starts_at')
            // One query for the counts. Without it this is a COUNT per row, and
            // the screen gets slower every webinar they ever run.
            ->withCount('registrations')
            ->get();

        return response()->json([
            'ok'       => true,
            'webinars' => $webinars->map(fn (Webinar $w) => $this->listPayload($w))->all(),
        ]);
    }

    /**
     * POST /api/v1/webinars
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $this->validatedWebinar($request);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }

        $creatorId = $this->creatorFor($request);

        // webinars.created_by_user_id is NOT NULL with an FK to users, while the
        // connector's created_by is nullable and is nulled if that user is ever
        // deleted. Without this, creating a webinar through a connector minted
        // by a since-deleted user would surface as a raw integrity error — a 500
        // on the website's form, with nothing on screen explaining why.
        if ($creatorId === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'This webinar cannot be created because CoreX has no System Owner to record as its author. Ask CoreX support to re-issue the website token.',
            ], 409);
        }

        // `slug` is nullable, so validate() omits the key entirely when the caller
        // did not send one — and the website does not: its "Link name" field is
        // blank by design, ConvertEmptyStringsToNull turns that into null, and it
        // drops nulls before posting. Reading $data['slug'] directly is therefore
        // an undefined key on the single most common create there is.
        $slugSource = trim((string) ($data['slug'] ?? '')) ?: $data['title'];

        $webinar = Webinar::create([
            ...$data,
            'slug'               => Webinar::uniqueSlug($slugSource),
            'created_by_user_id' => $creatorId,
        ]);

        Log::info('[webinars] created via site API', ['webinar_id' => $webinar->id, 'slug' => $webinar->slug]);

        return response()->json([
            'ok'      => true,
            'webinar' => $this->listPayload($webinar->loadCount('registrations')),
        ], 201);
    }

    /**
     * PUT /api/v1/webinars/{slug}
     *
     * Editing the date or the access window changes the deal only for people who
     * register FROM NOW ON. Everyone already registered keeps the deadline that
     * was copied onto their grant when they signed up — see Webinar::demoAccessEndsAt.
     * Shortening access somebody was already promised would be worse than the two
     * dates differing.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $webinar = Webinar::where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        try {
            $data = $this->validatedWebinar($request);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }

        // A blank slug on edit means "leave it alone", not "rebuild it from the
        // title" — the old link is already printed in somebody's email.
        if (($data['slug'] ?? '') === '') {
            unset($data['slug']);
        } elseif ($data['slug'] !== $webinar->slug) {
            $data['slug'] = Webinar::uniqueSlug($data['slug'], $webinar->id);
        }

        $webinar->update($data);

        return response()->json([
            'ok'      => true,
            'webinar' => $this->listPayload($webinar->fresh()->loadCount('registrations')),
        ]);
    }

    /**
     * DELETE /api/v1/webinars/{slug} — archive.
     *
     * Idempotent. Archiving an already-archived webinar is a success, because the
     * caller asked for a state and that state is the case; making it an error would
     * only punish a double-click.
     */
    public function archive(string $slug): JsonResponse
    {
        $webinar = Webinar::where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        if ($webinar->archived_at === null) {
            $webinar->update(['archived_at' => now()]);

            Log::info('[webinars] archived via site API', ['webinar_id' => $webinar->id]);
        }

        return response()->json([
            'ok'      => true,
            'webinar' => $this->listPayload($webinar->fresh()->loadCount('registrations')),
        ]);
    }

    /**
     * POST /api/v1/webinars/{slug}/join-link
     *
     * Save the joining link AND tell everyone who has already registered, in one press.
     *
     * Spec: .ai/specs/webinar-registration.md §4.4
     *
     * WHY THIS EXISTS. A webinar goes up as soon as its date is decided; the Zoom link
     * is generated days later. Everyone who registers in that window receives a
     * confirmation carrying no joining link at all, and PUT /{slug} only fixes that for
     * FUTURE registrants — it tells the existing cohort nothing. This is the only way
     * to reach them.
     *
     * ══ ONE TRANSACTION, AND IT IS A REAL ONE ══
     *
     * The website reports "saved and emailed to N" on a 200 and the operator believes
     * it. Saving join_url but queueing nothing — or queueing 200 emails and then
     * failing to save — makes that sentence a lie in a direction nobody can undo,
     * because email does not roll back.
     *
     * The save, the queueing and the stamps therefore share ONE transaction, and here
     * that is genuine atomicity rather than a gesture: QUEUE_CONNECTION=database with
     * DB_QUEUE_CONNECTION unset puts the `jobs` table on the SAME connection as
     * `webinars`, and config/queue.php sets 'after_commit' => false, so each queued
     * mail is INSERTed inside the open transaction. A throw anywhere rolls back the
     * join_url write, every stamp and every queued job together — and uncommitted
     * `jobs` rows are invisible to the worker, so no mail can escape a transaction
     * that later dies.
     *
     * THAT GUARANTEE IS A PROPERTY OF THE QUEUE CONFIG, NOT OF THIS CODE. Point
     * DB_QUEUE_CONNECTION at another database, or move the driver to Redis/SQS, and
     * the jobs stop rolling back while every test here still passes. Anyone making
     * that change must revisit this method.
     *
     * Deliberately UNLIKE SendWebinarReminders (§6.4), which stamps per row, swallows
     * one bad address and lets the next hourly sweep retry. That is right for a
     * recurring sweep with a second chance. This is a one-shot operator action whose
     * reported count is read as fact, so it is all-or-nothing instead.
     */
    public function sendJoinLink(Request $request, string $slug): JsonResponse
    {
        // Archived is a 404 and never a send: a cancelled webinar's cohort has already
        // been told it is off, and mailing them a joining link for it is worse than
        // doing nothing.
        $webinar = Webinar::notArchived()->where('slug', $slug)->first();

        if (! $webinar) {
            // NOT the shared notFound() helper. Its "not open for registration" is
            // untrue of an archived webinar and meaningless to an operator. §4.2's
            // deliberate vagueness exists to stop anonymous slug-probing; this
            // endpoint is reachable only with the admin connector token, so it can
            // afford to be honest.
            return response()->json([
                'ok'      => false,
                'message' => 'That webinar no longer exists, or has been archived.',
            ], 404);
        }

        $data = $request->validate([
            // Character-for-character the admin form's rule (Admin\WebinarController
            // ::validated()) apart from `required` — two front doors to one field must
            // not disagree about what a valid link is.
            'join_url' => ['required', 'url', 'max:500'],

            // NOT derivable from join_url — a Zoom link carries an encoded `pwd`
            // token, the displayed passcode is a different, short string, and someone
            // joining by Meeting ID in the app needs the latter. Nullable because a
            // link on its own is a legitimate state; see the three-way contract below
            // for how "" and an absent key differ.
            //
            // No trim, no case rule, no format rule ON PURPOSE. The Meeting ID keeps
            // its internal spaces and the passcode is case-sensitive, so anything that
            // "tidied" either would hand a registrant a code that does not work.
            'join_meeting_id' => ['nullable', 'string', 'max:100'],
            'join_passcode'   => ['nullable', 'string', 'max:100'],
        ], [
            // Verbatim from the admin form: the website renders CoreX's message
            // against its own input, so a message written for a different form would
            // read as nonsense there.
            'join_url.url' => 'The joining link needs to be a full web address, starting with https://',

            // The one message the admin form has never had. It treats join_url as
            // nullable because a webinar may legitimately not have a link yet; this
            // endpoint exists only to set one, so absent is a mistake, not a state.
            'join_url.required' => 'Paste the joining link before sending it to registrants.',

            // Written out rather than left to Laravel's default, which would render
            // "The join meeting id must not be greater than 100 characters" on the
            // website's own form. These messages are shown verbatim to an operator.
            'join_meeting_id.max'    => 'That Meeting ID is too long — it should look like 824 3770 8791.',
            'join_meeting_id.string' => 'Enter the Meeting ID exactly as the meeting shows it.',
            'join_passcode.max'      => 'That passcode is too long — it is the short code shown next to the Meeting ID.',
            'join_passcode.string'   => 'Enter the passcode exactly as the meeting shows it.',
        ]);

        $joinUrl = $data['join_url'];

        // ══ THE THREE-WAY CONTRACT, EXACTLY AS registration_closes_at (§4.5) ══
        //
        //   "join_passcode": "0ABcMc"  → set it
        //   "join_passcode": ""        → CLEAR it (write NULL)
        //   key absent                 → leave it UNCHANGED
        //
        // It works because ConvertEmptyStringsToNull turns "" into null before
        // validation, and validate() returns keys that are PRESENT BUT NULL while
        // omitting keys that are ABSENT. array_key_exists is therefore the whole
        // mechanism and is load-bearing: reading these with $request->input() instead,
        // or defaulting them into $data, turns "leave unchanged" into "clear it" — so
        // a re-send of an unchanged link would silently wipe the Meeting ID and
        // passcode off a webinar, with no error raised and no way to notice until a
        // registrant cannot get in.
        $updates = ['join_url' => $joinUrl];

        foreach (['join_meeting_id', 'join_passcode'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        $notified = DB::transaction(function () use ($webinar, $joinUrl, $updates) {
            $webinar->update($updates);

            // Read back AFTER the write, so an absent key resolves to what the webinar
            // already held. Like $joinUrl these are then passed to the Mailable by
            // value: the worker renders long after this transaction closes, and the
            // mail must carry what THIS send promised, not whatever a later press left
            // on the row.
            $joinMeetingId = $webinar->join_meeting_id;
            $joinPasscode  = $webinar->join_passcode;

            $now   = Carbon::now();
            $count = 0;

            // Chunked so a large cohort does not have to fit in this process's memory
            // at once. Still ONE transaction — chunking the reads is not chunking the
            // commits, which would reintroduce exactly the partial state this prevents.
            $webinar->registrations()
                ->with('webinar')
                ->orderBy('id')
                ->chunkById(200, function ($registrations) use ($joinUrl, $joinMeetingId, $joinPasscode, $now, &$count) {
                    foreach ($registrations as $registration) {
                        // The link is passed to the Mailable rather than read off the
                        // webinar at render time: the worker renders this long after
                        // the transaction closes, by which point the operator may have
                        // pressed again with a newer link. This captures what THIS
                        // send promised.
                        Mail::mailer('corex')
                            ->to($registration->email)
                            ->send(new WebinarJoinLinkMail(
                                $registration,
                                $joinUrl,
                                $joinMeetingId,
                                $joinPasscode,
                            ));

                        $registration->forceFill(['join_link_sent_at' => $now])->save();

                        WebinarJoinLinkSent::dispatch($registration);

                        $count++;
                    }
                });

            return $count;
        });

        // The COUNT, never the list. Registrants are personal data with no second copy
        // in CoreX (§4.3); naming them here would create that second copy in a file
        // with a different retention policy and no delete path.
        Log::info('[webinars] join link sent', [
            'webinar_id' => $webinar->id,
            'notified'   => $notified,
        ]);

        return response()->json([
            'ok'       => true,
            'join_url' => $joinUrl,

            // The receipt for what this send promised. Echoed for the same reason
            // join_url is: the console shows the operator what went out, and a
            // response that named only one of the three would read as if the other
            // two had not been saved.
            'join_meeting_id' => $webinar->join_meeting_id,
            'join_passcode'   => $webinar->join_passcode,

            // Always the full registration count, never a delta. Re-sending is the
            // point — Zoom links get regenerated, and the people already told are
            // precisely the people who most need telling again.
            'notified' => $notified,
        ]);
    }

    /**
     * GET /api/v1/webinars/{slug}/registrations?page=1&per_page=100
     *
     * THIS IS THE ONLY PLACE THESE PEOPLE EXIST (§3.2) — registrants are
     * deliberately not Contacts. It is personal data with no second copy, so it is
     * returned here and logged nowhere.
     */
    public function registrations(Request $request, string $slug): JsonResponse
    {
        $webinar = Webinar::where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        $perPage = max(1, min(500, (int) $request->query('per_page', 100)));

        $page = $webinar->registrations()
            ->with('grant')
            // Newest first: the reason to open this list is "who came in since I
            // last looked". id breaks ties, so a page boundary cannot show the
            // same person twice or skip one when two share a timestamp.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'ok'      => true,
            'webinar' => [
                'slug'      => $webinar->slug,
                'title'     => $webinar->title,
                'starts_at' => $webinar->starts_at->toIso8601String(),

                // §4.4. The website's screen renders "Link is set" / "Not set yet"
                // from this. Without it that badge always read "Not set yet", which
                // walks an operator straight into mailing the cohort a second time.
                // NOTE this is the ADMIN list; GET /{slug} (§4.1, public) still
                // returns no join_url — that is earned by registering.
                'join_url'  => $webinar->join_url,

                // All three, because the console pre-fills its form from this block
                // before a re-send. Returning only join_url would put an operator in
                // front of two blank boxes for values that ARE set — and posting that
                // form would send "" for both, clearing them.
                'join_meeting_id' => $webinar->join_meeting_id,
                'join_passcode'   => $webinar->join_passcode,
            ],
            'registrations' => collect($page->items())
                ->map(fn (WebinarRegistration $r) => $this->registrationPayload($r))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/webinars/{slug}/registrations.csv?format=zoom|full
     *
     * Streamed and chunked. A webinar with a few thousand sign-ups must not have to
     * fit in this process's memory before anyone can download it.
     *
     * The website pipes these bytes straight to the browser without touching them,
     * which is the point: the Zoom column order is a fact about Zoom's importer, and
     * it lives HERE, in the one place that generates it.
     */
    public function registrationsCsv(Request $request, string $slug): StreamedResponse|JsonResponse
    {
        $webinar = Webinar::where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        $format = $request->query('format') === 'zoom' ? 'zoom' : 'full';

        $filename = 'webinar-' . $webinar->slug . '-' . $format . '.csv';

        Log::info('[webinars] registrant CSV exported via site API', [
            'webinar_id' => $webinar->id,
            'format'     => $format,
        ]);

        return response()->streamDownload(function () use ($webinar, $format) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $format === 'zoom'
                // Zoom's bulk "Import from CSV" template, in its order. Zoom
                // matches on the header row, and it revises this from time to
                // time — check it against the sample CSV Zoom offers on that
                // import screen before a big webinar.
                ? ['Email Address', 'First Name', 'Last Name', 'Company']
                : ['First Name', 'Last Name', 'Email', 'Company', 'Phone', 'Registered at', 'Demo access', 'Access ends', 'Reminder sent']);

            // chunkById, not chunk: offset paging over a non-unique sort column
            // can repeat or skip rows at a chunk boundary when several people
            // registered in the same second — and a registrant silently missing
            // from a Zoom import is someone who never gets their joining link.
            // Registrations are only ever appended, so id order is sign-up order.
            $webinar->registrations()
                ->with('grant')
                ->chunkById(200, function ($rows) use ($out, $format) {
                    foreach ($rows as $r) {
                        [$first, $last] = $this->splitName($r->name);

                        fputcsv($out, $format === 'zoom'
                            ? [$r->email, $first, $last, $r->company_name]
                            : [
                                $first,
                                $last,
                                $r->email,
                                $r->company_name,
                                $r->phone,
                                $r->created_at?->format('Y-m-d H:i'),
                                $r->accessStatusLabel(),
                                $r->grant?->expires_at?->format('Y-m-d H:i'),
                                $r->reminder_sent_at?->format('Y-m-d H:i'),
                            ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---- Admin payload helpers ---------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Webinar $webinar): array
    {
        return [
            'slug'                => $webinar->slug,
            'title'               => $webinar->title,
            'description'         => $webinar->description,
            'starts_at'           => $webinar->starts_at->toIso8601String(),
            'registration_closes_at' => $webinar->registration_closes_at?->toIso8601String(),
            'duration_minutes'    => $webinar->duration_minutes,
            // The edit form needs these five; the PUBLIC read (§4.1) still must not
            // carry the joining details, which is why that method builds its own body.
            'join_url'               => $webinar->join_url,
            'join_meeting_id'        => $webinar->join_meeting_id,
            'join_passcode'          => $webinar->join_passcode,
            'access_ends_days_after' => $webinar->access_ends_days_after,
            'reminder_hours_before'  => $webinar->reminder_hours_before,
            'registration_open'   => $webinar->isOpenForRegistration(),
            'status_label'        => $webinar->statusLabel(),
            'demo_access_ends_at' => $webinar->demoAccessEndsAt()->toIso8601String(),
            'registration_count'  => (int) ($webinar->registrations_count ?? $webinar->registrations()->count()),
            'registration_url'    => rtrim((string) config('integrations.corex_website_url'), '/') . '/webinars/' . $webinar->slug,
            'archived'            => $webinar->isArchived(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(WebinarRegistration $r): array
    {
        [$first, $last] = $this->splitName($r->name);

        return [
            'id'         => $r->id,
            'first_name' => $first,
            'last_name'  => $last,
            // The stored truth, alongside the derived halves — so nothing the
            // website shows depends on the guess below.
            'name'                => $r->name,
            'email'               => $r->email,
            'company_name'        => $r->company_name,
            'phone'               => $r->phone,
            'registered_at'       => $r->created_at?->toIso8601String(),
            'demo_access_status'  => $r->accessStatusLabel(),
            'demo_access_ends_at' => $r->grant?->expires_at?->toIso8601String(),
            'reminder_sent_at'    => $r->reminder_sent_at?->toIso8601String(),
        ];
    }

    /**
     * Who to record as the author of a webinar created through the API.
     *
     * The caller is a website, not a person, so there is no Auth::id(). The truest
     * available answer is whoever minted the token this request arrived on. That
     * column is nullable and is nulled if the user is deleted, so it falls back to
     * a System Owner — and returns null rather than inventing one if there is none,
     * because a wrong name on a sales record is worse than a refusal that says why.
     */
    private function creatorFor(Request $request): ?int
    {
        $mintedBy = $request->attributes->get('site_connector')?->created_by;

        if ($mintedBy && User::whereKey($mintedBy)->exists()) {
            return (int) $mintedBy;
        }

        // Resolved by query, NOT via User::isOwnerRole(). That method goes through
        // effectiveAgencyId(), which reads session('active_agency_id') — and this
        // is a server-to-server request with no session and no logged-in user.
        // Owner roles are global (agency_id NULL), which is what makes this a
        // straight lookup rather than an agency-scoped one.
        $ownerRoles = Role::whereNull('agency_id')
            ->where('is_owner', true)
            ->pluck('name');

        if ($ownerRoles->isEmpty()) {
            return null;
        }

        return User::whereIn('role', $ownerRoles)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Split a stored full name into the halves Zoom and the website's list want.
     *
     * §3.2 stores one `name`. Splitting at the FIRST space keeps the surname whole
     * — "Jan van der Merwe" becomes "Jan" + "van der Merwe", which is right, where
     * splitting at the last space would produce "Jan van der" + "Merwe", which is
     * not a name anyone has. Rejoining is lossless either way, so the website's list
     * shows the true full name regardless.
     *
     * This disappears when the first_name/last_name columns land. Spec §4.3.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Validation for create and edit.
     *
     * Deliberately the same rules and the same plain-English messages as
     * Admin\WebinarController::validated(). Two front doors to one record must not
     * disagree about what a valid record is — and these messages are rendered
     * verbatim by the website against its own fields.
     *
     * @return array<string, mixed>
     *
     * ══ registration_closes_at CARRIES A THREE-WAY CONTRACT — DO NOT REFACTOR IT AWAY ══
     *
     * The website relies on the difference between three requests (§4.5):
     *
     *   "registration_closes_at": "2026-09-08T17:00:00+02:00"  → set the cut-off
     *   "registration_closes_at": ""                           → CLEAR it (write NULL)
     *   key absent                                             → leave it UNCHANGED
     *
     * That works because ConvertEmptyStringsToNull turns "" into null before validation,
     * and validate() returns keys that are PRESENT BUT NULL while omitting keys that are
     * ABSENT — so update($data) nulls the column in the second case and never mentions
     * it in the third.
     *
     * It is implicit, and it is load-bearing. Reading this field with
     * $request->input('registration_closes_at') instead, or array_merge-ing defaults into
     * $data, would turn "leave unchanged" into "clear it" — silently wiping a cut-off on
     * every unrelated edit, with no error raised. WebinarRegistrationCutOffTest pins all
     * three rows.
     *
     * @throws ValidationException
     */
    private function validatedWebinar(Request $request): array
    {
        return $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'slug'                   => ['nullable', 'string', 'max:255'],
            'description'            => ['nullable', 'string', 'max:5000'],
            'starts_at'              => ['required', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'before:starts_at'],
            'duration_minutes'       => ['nullable', 'integer', 'min:5', 'max:1440'],
            'join_url'               => ['nullable', 'url', 'max:500'],

            // The same three-way contract as registration_closes_at above, for the
            // same reason and by the same mechanism: "" clears, an absent key leaves
            // the value alone. Stored verbatim — the Meeting ID keeps its internal
            // spaces and the passcode is case-sensitive, so there is no trim, no
            // case rule and no format rule here on purpose.
            'join_meeting_id'        => ['nullable', 'string', 'max:100'],
            'join_passcode'          => ['nullable', 'string', 'max:100'],
            'access_ends_days_after' => ['required', 'integer', 'min:0', 'max:365'],
            'reminder_hours_before'  => ['required', 'integer', 'min:1', 'max:336'],
        ], [
            'title.required'                  => 'Give the webinar a title — registrants see it in their confirmation email.',
            'starts_at.required'              => 'Set the date and time the webinar starts.',
            'registration_closes_at.before'   => 'Registration has to close before the webinar starts. Leave it blank to keep sign-ups open right up to the start.',
            'registration_closes_at.date'     => 'Enter a valid date and time for registration to close.',
            'join_url.url'                    => 'The joining link needs to be a full web address, starting with https://',
            'join_meeting_id.max'             => 'That Meeting ID is too long — it should look like 824 3770 8791.',
            'join_passcode.max'               => 'That passcode is too long — it is the short code shown next to the Meeting ID.',
            'access_ends_days_after.required' => 'Say how long demo access should last.',
            'reminder_hours_before.required'  => 'Say how far ahead the reminder email should go out.',
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'That webinar is not open for registration.',
        ], 404);
    }
}
