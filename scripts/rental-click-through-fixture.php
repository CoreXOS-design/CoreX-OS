<?php

/**
 * Throwaway fixture creator/cleaner for scripts/rental-click-through.mjs.
 *
 * Johan/cc1's own guidance, 2026-09-15: a click-through gate MUTATES state
 * (strikes a line, submits, approves, declines) — those are one-way
 * transitions, so it can never safely share rental-smoke.mjs's persistent,
 * read-only fixtures (app 22, app 4, or any of Johan's own real applications
 * — 70/76/107 — which are always off-limits). Every run creates its OWN
 * throwaway agency, users, applications, document and marks; the .mjs
 * script cleans them up (soft-delete, never hard-delete) whether the run
 * passed or failed.
 *
 * Two applications, not one, because the controls this gate covers span
 * TWO different, mutually exclusive lifecycle stages:
 *   - App A ('in_progress'): strike/restore, add-line-manually, the capture
 *     chip, submit-for-approval, send-back-to-applicant. All of these need
 *     an application the AGENT still owns.
 *   - App B ('under_assessment', submitted_for_approval_at set): the
 *     authoriser's approve/decline. Needs a DIFFERENT application already
 *     past the agent's own stage — testing approve/decline on App A after
 *     submitting it would consume the very state the submit test just
 *     proved, and the two checks would no longer be independent.
 *
 * Usage:
 *   php8.2 rental-click-through-fixture.php --app-root=/corex-qa1 --create
 *     -> prints one JSON object to stdout with every id the .mjs script needs
 *   php8.2 rental-click-through-fixture.php --app-root=/corex-qa1 --cleanup=<json from --create>
 *     -> soft-deletes everything named in that JSON (idempotent — safe to
 *        run twice, safe to run on a partial/failed --create's output)
 */

$opts = getopt('', ['app-root:', 'create', 'cleanup:']);
if (empty($opts['app-root']) || (!isset($opts['create']) && empty($opts['cleanup']))) {
    fwrite(STDERR, "Usage: --app-root=<path> (--create | --cleanup=<json>)\n");
    exit(2);
}

$appRoot = rtrim($opts['app-root'], '/');
chdir($appRoot);
require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationDeclineReasonTemplate;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (isset($opts['create'])) {
    $stamp = 'clickthrough-' . date('YmdHis') . '-' . substr(uniqid(), -5);

    $agency = Agency::create(['name' => 'Click-Through Gate Co', 'slug' => $stamp]);
    $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
    $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Gate Agent']);
    $ro = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Gate Authoriser']);
    $agency->update(['rental_application_ro_user_ids' => [$ro->id], 'rental_application_co_user_ids' => [$ro->id]]);
    // 2026-09-13 — a real 'own'-ceiling role, for the scope-toggle gate:
    // proves a hand-crafted ?scope=all is still clamped server-side even
    // when the toggle itself would never render one for this user.
    $plainAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'name' => 'Gate Plain Agent']);

    $contactA = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Gate', 'last_name' => 'ApplicantA', 'email' => $stamp . '-a@example.test']);
    $contactB = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Gate', 'last_name' => 'ApplicantB', 'email' => $stamp . '-b@example.test']);

    // AT-410b, 2026-09-13 — a brand-new agency has zero decline-reason
    // templates (they're agency-configured via cc2's template CRUD), and
    // the authoriser's Decline confirm button now requires one to be
    // chosen alongside the free-text note (review.blade.php:1829). Without
    // this, check 14 in rental-click-through.mjs finds an empty <select>
    // and can never exercise the real precondition.
    RentalApplicationDeclineReasonTemplate::create([
        'agency_id' => $agency->id, 'reason' => 'Affordability', 'guidance' => 'Gate check guidance text.',
        'sort_order' => 0, 'created_by' => $ro->id,
    ]);

    // ── App A — agent-owned controls ──
    $appA = RentalApplication::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contactA->id,
        'created_by_user_id' => $agent->id, 'status' => 'in_progress', 'current_generation' => 1,
        'token' => $stamp . '-a', 'monthly_salary' => 20000,
        'employment_type' => 'permanently_employed',
    ]);

    // A real, readable PDF, correctly owned — cc1's own guidance: store()
    // through the real Storage facade (same disk/path shape uploadDocument()
    // uses), then chown so the real php-fpm (www-data) user can read it,
    // exactly as if an agent had uploaded it.
    $pdfSource = $appRoot . '/tests/Fixtures/viewing_pack/one_page_text.pdf';
    $storedPath = "rental-applications/{$appA->id}/documents/" . Str::random(20) . '.pdf';
    Storage::disk('local')->put($storedPath, file_get_contents($pdfSource));
    $absolutePath = Storage::disk('local')->path($storedPath);
    @chown($absolutePath, 'www-data');
    @chgrp($absolutePath, 'www-data');
    @chown(dirname($absolutePath), 'www-data');
    @chgrp(dirname($absolutePath), 'www-data');

    $document = Document::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id,
        'original_name' => 'gate-fixture.pdf', 'storage_path' => $storedPath, 'disk' => 'local',
        'mime_type' => 'application/pdf', 'size' => filesize($absolutePath),
        'source_type' => 'rental_application', 'source_id' => $appA->id, 'uploaded_by' => $agent->id,
    ]);

    RentalApplicationAssessment::create([
        'agency_id' => $agency->id, 'rental_application_id' => $appA->id,
        'statement_period_from' => '2026-01-01', 'statement_period_to' => '2026-01-31', 'statement_months' => 1,
    ]);

    // One ANCHORED mark (real document, plausible on-page geometry) so the
    // capture chip's EDIT path (click an existing mark -> Save) has a real
    // element to click — geometry doesn't need to be pixel-accurate against
    // the rendered page for a click-through check, only present and valid
    // per the same floor/ceiling captureEntryCreate() itself enforces.
    $anchoredMark = RentalApplicationDocumentMark::create([
        'agency_id' => $agency->id, 'document_id' => $document->id, 'rental_application_id' => $appA->id,
        'mark_uid' => (string) Str::uuid(), 'type' => 'highlight', 'page' => 0,
        'points' => [['x' => 100, 'y' => 100], ['x' => 300, 'y' => 130]], 'width' => 20,
        'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent', 'source' => 'human',
        'entry_type' => 'income', 'entry_date' => '2026-01-15', 'entry_description' => 'Gate fixture salary', 'entry_amount' => 15000,
    ]);

    // Two UNANCHORED marks for strike/restore (income and expense — Johan's
    // rule applies to both).
    $unanchoredIncome = RentalApplicationDocumentMark::create([
        'agency_id' => $agency->id, 'rental_application_id' => $appA->id,
        'mark_uid' => (string) Str::uuid(), 'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
        'source' => 'human', 'entry_type' => 'income', 'entry_date' => '2026-01-10', 'entry_description' => 'Gate fixture wages', 'entry_amount' => 5000,
    ]);
    $unanchoredExpense = RentalApplicationDocumentMark::create([
        'agency_id' => $agency->id, 'rental_application_id' => $appA->id,
        'mark_uid' => (string) Str::uuid(), 'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
        'source' => 'human', 'entry_type' => 'expense', 'entry_date' => '2026-01-12', 'entry_description' => 'Gate fixture rent', 'entry_amount' => 2000,
    ]);

    // ── App B (approve) and App C (decline) — authoriser-owned controls.
    // Two separate applications, not one: approve and decline are each a
    // ONE-WAY transition out of 'under_assessment' — testing both on the
    // same app would mean the second check runs against an application the
    // first check has already moved out of the state it needs.
    $contactC = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Gate', 'last_name' => 'ApplicantC', 'email' => $stamp . '-c@example.test']);
    $appB = RentalApplication::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contactB->id,
        'created_by_user_id' => $agent->id, 'status' => 'under_assessment', 'current_generation' => 1,
        'token' => $stamp . '-b', 'monthly_salary' => 20000, 'employment_type' => 'permanently_employed',
        'submitted_for_approval_at' => now(),
    ]);
    $appC = RentalApplication::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contactC->id,
        'created_by_user_id' => $agent->id, 'status' => 'under_assessment', 'current_generation' => 1,
        'token' => $stamp . '-c', 'monthly_salary' => 20000, 'employment_type' => 'permanently_employed',
        'submitted_for_approval_at' => now(),
    ]);
    foreach ([$appB, $appC] as $app) {
        RentalApplicationAssessment::create([
            'agency_id' => $agency->id, 'rental_application_id' => $app->id,
            'statement_period_from' => '2026-01-01', 'statement_period_to' => '2026-01-31', 'statement_months' => 1,
        ]);
        RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $app->id,
            'mark_uid' => (string) Str::uuid(), 'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'source' => 'human', 'entry_type' => 'income', 'entry_date' => '2026-01-10', 'entry_description' => 'Gate fixture salary', 'entry_amount' => 12000,
        ]);
    }

    echo json_encode([
        'agency_id' => $agency->id, 'branch_id' => $branch->id,
        'agent_user_id' => $agent->id, 'ro_user_id' => $ro->id, 'plain_agent_user_id' => $plainAgent->id,
        'contact_a_id' => $contactA->id, 'contact_b_id' => $contactB->id, 'contact_c_id' => $contactC->id,
        'app_a_id' => $appA->id, 'app_b_id' => $appB->id, 'app_c_id' => $appC->id,
        'document_id' => $document->id,
        'document_storage_path' => $storedPath,
        'anchored_mark_uid' => $anchoredMark->mark_uid,
        'unanchored_income_mark_uid' => $unanchoredIncome->mark_uid,
        'unanchored_expense_mark_uid' => $unanchoredExpense->mark_uid,
    ]) . PHP_EOL;
    exit(0);
}

if (!empty($opts['cleanup'])) {
    $ids = json_decode($opts['cleanup'], true);
    if (!is_array($ids)) {
        fwrite(STDERR, "Could not parse --cleanup JSON\n");
        exit(2);
    }

    // Soft delete only (non-negotiable #1) — every model here supports it
    // except RentalApplicationAssessment, which has no SoftDeletes trait at
    // all; left in place, same as the app already tolerates elsewhere
    // (an orphaned assessment row against a soft-deleted application is not
    // a new problem this script introduces).
    foreach (['app_a_id', 'app_b_id', 'app_c_id'] as $key) {
        if (!empty($ids[$key])) {
            RentalApplication::withTrashed()->find($ids[$key])?->delete();
        }
    }
    if (!empty($ids['document_id'])) {
        Document::withTrashed()->find($ids['document_id'])?->delete();
    }
    // Soft-deleting the Document row doesn't touch the physical file —
    // disk hygiene (CLAUDE.md) means the real PDF this run wrote needs its
    // own explicit cleanup, not left behind as an orphan on every gate run.
    if (!empty($ids['document_storage_path'])) {
        Storage::disk('local')->delete($ids['document_storage_path']);
        // Deleting the file leaves its now-empty rental-applications/{id}/
        // and rental-applications/{id}/documents/ directories behind —
        // harmless but real cruft that would otherwise accumulate one
        // empty tree per gate run, forever. Both levels, not just the
        // immediate parent.
        Storage::disk('local')->deleteDirectory(dirname($ids['document_storage_path']));
        Storage::disk('local')->deleteDirectory(dirname(dirname($ids['document_storage_path'])));
    }
    if (!empty($ids['document_id'])) {
        // The highlighter's own raster cache — page-N.png previews and
        // total-pages.txt, generated lazily under document-highlights/
        // cache/doc-{id}-v{version}/ the first time anyone opens this
        // document's markup viewer (exactly what checks #6/#7 do on every
        // gate run). Not covered by the two deletes above at all — a
        // completely separate cache tree the Document row's own delete
        // knows nothing about. The version suffix is generated lazily and
        // unknown to this script ahead of time, so glob on the one part we
        // do know: the document id.
        foreach (glob(storage_path("app/private/rental-applications/document-highlights/cache/doc-{$ids['document_id']}-*")) ?: [] as $dir) {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }
    RentalApplicationDocumentMark::withTrashed()
        ->whereIn('mark_uid', array_filter([
            $ids['anchored_mark_uid'] ?? null,
            $ids['unanchored_income_mark_uid'] ?? null,
            $ids['unanchored_expense_mark_uid'] ?? null,
        ]))
        ->get()->each->delete();
    foreach (['contact_a_id', 'contact_b_id', 'contact_c_id'] as $key) {
        if (!empty($ids[$key])) {
            Contact::withTrashed()->find($ids[$key])?->delete();
        }
    }
    // Both fixture users are role=admin in the SAME throwaway agency —
    // deleting the second one makes it the LAST admin, and User's own
    // static::deleting guard (LastAdminException) correctly refuses that,
    // model-level, regardless of caller. Established precedent from this
    // same session's own PHPUnit fixture cleanup: never bypass that guard
    // to tidy up test data — leave the one it blocks, a harmless orphaned
    // row in a throwaway agency nobody will ever use again.
    foreach (['agent_user_id', 'ro_user_id', 'plain_agent_user_id'] as $key) {
        if (!empty($ids[$key])) {
            try {
                User::withTrashed()->find($ids[$key])?->delete();
            } catch (\App\Exceptions\LastAdminException $e) {
                fwrite(STDERR, "Left in place (last admin guard): user {$ids[$key]}\n");
            }
        }
    }
    if (!empty($ids['branch_id'])) {
        Branch::withTrashed()->find($ids['branch_id'])?->delete();
    }
    if (!empty($ids['agency_id'])) {
        Agency::withTrashed()->find($ids['agency_id'])?->delete();
    }

    echo "CLEANED\n";
    exit(0);
}
