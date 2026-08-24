# SyncReferenceData: discovery by marker interface, not a hand list — 2026-08-24

Staging only, per instruction. Nothing applied to live. Built in an isolated worktree
(`/mnt/HC_Volume_103099143/corex-worktrees/qa1-syncable-seeder-discovery`) because cc5 was
mid-rebase against `/corex-qa1`'s shared `.git` when this task started — no git write ever
touched that directory this session.

## The design

`App\Contracts\SyncableReferenceSeeder` — an empty marker interface. `SyncReferenceData`
walks `database/seeders/` to enumerate *candidate* class names (the same way Composer's PSR-4
autoloader resolves a path to a class — this is enumeration only), then includes a class in
the run **only if** `is_subclass_of($class, SyncableReferenceSeeder::class)`. Nothing about a
file's name or location decides inclusion; only the interface check does.

Deliberately opt-in rather than a naming/glob scan: `SalesMandatoryDisclosureSeeder` and
`SellerMandatoryAddendumSeeder` — the two dead, superseded seeders found chasing the original
MDF/Addendum B gap — live in the same directory and share large parts of the same name as the
real ones. Staging already proved that running `SalesMandatoryDisclosureSeeder` after the real
one silently swaps the row's content back to an untested template, same id, no error. A scan
selecting on filename or directory membership would sweep both back in. They deliberately do
not implement the interface, and are excluded by that — not by a denylist, which fails open.

All 12 currently-registered seeders marked with the interface (unchanged behaviour otherwise).

## Proof — Staging (`hfc_staging`, via `nexus`)

**1. Set identical.** Diffed the discovered set against the exact old hardcoded list (extracted
from the pre-refactor commit): sorted diff, exit code 0 — same 12 classes, no more, no fewer.

**Order changed, and that's addressed, not ignored.** Discovery sorts alphabetically;
the old array had a hand-authored order (dated `NotificationEventTypeSeeder` first,
`HfcAddendumBEsignSeeder` last). Order diff is real (confirmed, not glossed over). Checked all
12 for actual dependency: grepped every seeder for a reference to another seeder's class — zero
hits (one comment mentions `MarketingPermissionEsignSeeder::nf`, a seeder outside this set, as
a style note, not a runtime call). The one pair sharing a table
(`DataDictionarySeeder`/`ReferencePackDictionarySeeder`, both write `docuperfect_data_dictionary`
rows... actually `DataDictionaryEntry`) — `ReferencePackDictionarySeeder` upserts on its own
`(agency_id, key, version)`, never reads what `DataDictionarySeeder` wrote. Every one of the 12
already follows the same documented "never trust another seeder's output, find-or-create your
own FK dependency" pattern (their own comments say so, independently of this refactor). The one
real cross-cutting ordering rule — `roles` must exist before `corex:sync-permissions` fans out
grants — is protected structurally: `handle()` runs its seeders loop to completion before its
commands loop starts, regardless of the seeders' internal order. Not run from a from-empty
database in both orders (these are long-established, already-idempotent seeders; the risk
after this review reads as low, and that's stated as a judgement, not a from-empty-DB proof).

**2. Dead seeders absent, by name.** `grep -c "SalesMandatoryDisclosureSeeder\b\|SellerMandatoryAddendumSeeder"`
against the discovered `--dry-run` output: **0**, on both QA1 and Staging.

**3 & 4. Idempotency and content, together — the proof that matters.** Captured a SHA-256 hash
of the MDF row's full content (every column except `id`/`created_at`/`updated_at`) at three
points: before any run, after run 1, after run 2.

```
baseline:  a77f713fc214cdd3f14d2dee51d2cbf8ff73fea79cad0dd9dff8ab92f1496cbd
after run 1: a77f713fc214cdd3f14d2dee51d2cbf8ff73fea79cad0dd9dff8ab92f1496cbd
after run 2: a77f713fc214cdd3f14d2dee51d2cbf8ff73fea79cad0dd9dff8ab92f1496cbd
```
Identical all three times, on both QA1's real database and Staging. `docuperfect_templates`
row count stable at 79 throughout (no duplication). Same result reproduced independently on
QA1's own database before the Staging run (hash `a77f7...` matched there too, different row
id, same content — expected, id is excluded from the hash).

**Honest nuance, not swept under "idempotent":** the second run DOES issue an `UPDATE` against
both the MDF and Addendum B rows — `updated_at` moves both times, because those two seeders
call `->update($row)` unconditionally whenever the row is found, a pattern that predates this
refactor and is unrelated to it. "Idempotent" here means *repeatable with the same result*,
proven by the content hash — not *inert on a re-run*, which would be a stronger and untrue
claim. Checked directly (`updated_at >= NOW() - INTERVAL 2 MINUTE`) that these are the only two
rows in the whole table touched by a re-run — nothing else moved.

**5. Opt-in proven directly, not just designed that way.** In the isolated worktree, created a
throwaway seeder (`ProofUnmarkedTestSeeder`) that does NOT implement the interface. `--dry-run`
without editing `SyncReferenceData.php`: absent from the discovered list. Added
`implements SyncableReferenceSeeder` to the same file, same dry-run, no other change: now
present. Deleted before commit — not part of the real registered set.

## Coordination

`/corex-qa1` was never touched by any git write this session once cc5's concurrent rebase was
flagged — all work happened in the isolated worktree above, then cherry-picked directly to
Staging. `/corex-qa1`'s working directory still has the original uncommitted edits sitting on
disk (harmless, not staged, not going anywhere) until cc5 releases it and they can be reverted
or discarded there.

## Status

Committed and pushed: `qa1-syncable-seeder-discovery` (own branch,
`e71bf5013` → GitHub), cherry-picked onto `origin/Staging` (`9a0111196`). **Not applied to
live.** Live's `SyncReferenceData.php` still has the pre-refactor hardcoded list from the
MDF/Addendum B deploy — this refactor is Staging-only until Johan says otherwise.
