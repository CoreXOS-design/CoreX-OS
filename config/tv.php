<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy shared-token TV branch allow-list
    |--------------------------------------------------------------------------
    |
    | app/Http/Controllers/TV/BranchTvController.php ("/tv/branch/{branchId}")
    | is gated only by a single shared TV_TOKEN (see TvTokenMiddleware) — the
    | token proves possession, not entitlement to any particular branch. On
    | its own, any holder of the one valid token could enumerate
    | /tv/branch/1..N and view every agency's sales targets, deal status, and
    | agent leaderboards.
    |
    | This is a STOPGAP, not a full fix: this allow-list restricts the shared
    | token to a specific, explicitly provisioned set of branch IDs (comma
    | separated in TV_ALLOWED_BRANCH_IDS). Fail-closed: if the env var is
    | unset or empty, every branch ID is rejected (404), mirroring
    | TvTokenMiddleware's "TV_TOKEN not set => block by default" behaviour.
    |
    | The durable fix is retiring this legacy route in favour of the newer
    | per-branch 6-digit TvAccessCode flow (App\Http\Controllers\TV\TvController),
    | which is already correctly scoped to one branch/agency per code and
    | does not share a single platform-wide secret across every branch.
    |
    */

    'allowed_branch_ids' => array_values(array_filter(array_map(
        static fn ($id) => (int) trim($id),
        explode(',', (string) env('TV_ALLOWED_BRANCH_IDS', ''))
    ), static fn ($id) => $id > 0)),

];
