---
name: schema-dump
description: Regenerate database/schema/mysql-schema.sql with scripts/schema-dump.sh after migrations land, and how the snapshot interacts with lane tests. Use whenever a migration is added/merged, a merge conflicts on the snapshot, or tests are missing a new table/column or reference row.
---
# Schema snapshot (non-negotiable 12a)

`database/schema/mysql-schema.sql` is loaded by `RefreshDatabase`/`lane-test.sh` instead of replaying ~190+ migrations (~83% faster bootstrap). Migrations newer than the snapshot are applied on top, so a snapshot a few migrations behind costs seconds, not correctness. A stale one makes new tables/columns/reference rows silently absent in other lanes' runs (looks like an unrelated failure elsewhere).

- **The ONLY sanctioned way: `bash scripts/schema-dump.sh`** (from the worktree root, no arguments). Never hand-edit the snapshot, never run a bare `artisan schema:dump`, never `migrate:fresh` + `schema:dump` against your lane schema (both produced bad snapshots: charset noise, missing tables, the `_corex_lane_test_fingerprint` bookkeeping table).
- It builds its OWN scratch schema on the tests-only MySQL (refuses without `/root/.lanetest-mysql-credentials`), migrates **from scratch**, dumps, **carries GLOBAL reference rows** that data-seeding migrations insert (tables with no `agency_id`, or `agency_id IS NULL` rows; NOT agency-1/HFC rows, NOT the `agencies` row, NOT `roles`/`role_permissions` - the script's `SKIP_DATA_TABLES_RE` comment lists every exclusion and why), **strips `DEFINER` clauses** (a baked-in definer breaks everyone but the dumping user with ERROR 1227; shipped once in `52d921862`), strips redundant column charsets, refuses a dump containing the bookkeeping table, runs `check-schema-snapshot.sh`, and moves the file into place only on success.
- Takes ~20 minutes and holds the tests-instance setup lock the whole time (other lanes' cold bootstraps wait). Needs the worktree's OWN `vendor/`. Run it **once per batch of merged migrations, not once per migration.**
- Merge conflict on the snapshot: take either side, then regenerate with the script. Never merge by hand.
- After regenerating: `grep` for a table/column only your migration introduces; commit the snapshot (alone or with the migration); run `bash scripts/lane-test.sh --fresh <your test file>` once so your schema rebuilds from it.
- A test failing only because a starting-data table is empty (or only because it isn't) is fixed in the script's lists, never by editing the snapshot.
- A slow snapshot load (minutes) is not staleness. Stale = the snapshot's git history predates a merged migration.
- Windows/Laragon developers: `mysql`/`mysqldump` must be on PATH; `scripts/dev-check.ps1` section 10 fails the build if a DEFINER clause returns.
