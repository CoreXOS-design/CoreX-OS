<?php

namespace Database\Seeders;

use App\Models\DemoTncVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo T&C version 1.
 *
 * Spec: .ai/specs/demo-access-control.md §10
 *
 * ══ MUST-TRAVEL GLOBAL REFERENCE DATA ══
 *
 * Registered in app/Console/Commands/Deploy/SyncReferenceData.php, because
 * seeders do NOT run on a `git pull` deploy (AT-162 — the bug-class that left the
 * "Private" calendar type missing on live).
 *
 * Without v1 in the database, DemoTncVersion::current() is null, the clickwrap has
 * nothing to render, hasAcceptedCurrentTnc() is false forever, and EVERY prospect
 * is hard-blocked at the terms screen. This row is not decoration — it is load-
 * bearing for the whole gate.
 *
 * IDEMPOTENT: firstOrCreate on version = 1. It runs on every deploy and must never
 * duplicate, and — critically — must never OVERWRITE. Published T&C versions are
 * immutable (§4.1): if this seeder updated the body of v1, every existing v1
 * acceptance would silently become evidence of text nobody ever agreed to. So it
 * creates or it does nothing.
 */
class DemoTncVersionSeeder extends Seeder
{
    public function run(): void
    {
        DemoTncVersion::firstOrCreate(
            ['version' => 1],
            [
                'body'         => $this->v1Body(),
                'published_at' => Carbon::now(),
                // No publisher: this version was provisioned by the system, not by
                // a person. Attributing it to whoever ran the deploy would be a lie
                // in an evidence table.
                'published_by_user_id' => null,
            ]
        );
    }

    private function v1Body(): string
    {
        return <<<'TXT'
CoreX OS — Demo Terms of Use

1. What this is
This is a demonstration of CoreX OS, provided by RR Technologies for evaluation
purposes only. It is not a live system and must not be used to conduct real
business.

2. Shared environment
The demo is a shared sandbox. Other people evaluating CoreX use the same
environment at the same time. You may see data they have entered, and they may
see data you have entered. Treat everything in the demo as visible to others.

3. The data is temporary
The demo database is erased and rebuilt from scratch every three days. Anything
you create, upload, or change will be permanently destroyed on that cycle. There
is no backup and nothing can be recovered.

4. Do not enter real personal information
Because of clauses 2 and 3, you must not enter real client, seller, buyer,
tenant, or employee information into the demo. Do not upload real identity
documents, FICA documents, contracts, or bank details. Use only the sample data
provided, or information you have invented.

5. Your access
Your access is personal to you and to the company named in your invitation. Do
not share your access code. Access is time-limited and may be withdrawn at any
time without notice.

6. What we record
We record when you sign in, which pages you view, and that you accepted these
terms. We use this to understand which parts of CoreX are useful to you and to
follow up with you about your evaluation. Every page you view is watermarked with
your company name and email address.

7. Confidentiality
CoreX OS, including its features, screens, and workings, is confidential. Do not
share screenshots, recordings, or descriptions of it with anyone outside the
company named in your invitation without our written permission.

8. No warranty
The demo is provided as-is. Features may be incomplete, data may be inaccurate,
and the system may be unavailable at any time. Nothing you see in the demo is a
commitment to deliver any specific feature.

9. South African law
These terms are governed by the laws of the Republic of South Africa.

By accepting, you confirm you have read these terms and agree to them on behalf
of the company named in your invitation.
TXT;
    }
}
