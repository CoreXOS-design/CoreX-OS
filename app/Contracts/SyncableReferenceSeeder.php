<?php

namespace App\Contracts;

/**
 * Marker interface — a seeder implementing this is discovered and run by
 * `deploy:sync-reference-data` (AT-162) automatically, on every deploy.
 *
 * Deliberately opt-in, not a naming convention or a directory scan. The MDF
 * and Addendum B gap (2026-08-24) happened because two real, verified
 * document-template seeders were never hand-added to that command's list —
 * but the fix for "someone forgot to list it" cannot be "list everything
 * found nearby," because two OTHER seeders live in the exact same directory,
 * share large parts of the same name, and are dead: superseded first
 * attempts that were never actually run anywhere. Proved on Staging that
 * running one of them (`SalesMandatoryDisclosureSeeder`) after the real one
 * silently swaps the row's content back to an untested template — same id,
 * no error, no new row. A scan that included every seeder in the directory,
 * or every class matching a `*Seeder` pattern, would sweep those back in
 * the moment this file exists.
 *
 * So: implementing this interface is the ONLY thing that gets a seeder run
 * by the deploy command. Not its filename, not its location, not its class
 * name. A seeder that doesn't implement it is invisible to
 * `deploy:sync-reference-data` regardless of what else is true about it —
 * including the two dead ones above, which deliberately do NOT implement it.
 *
 * Implementers must be idempotent and safe to re-run on an environment that
 * already has the data (find-or-update, never a blind insert) — the command
 * runs on every deploy, not once.
 */
interface SyncableReferenceSeeder
{
}
