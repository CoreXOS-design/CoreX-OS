# QA1 nightly sync — missed agency-settings audit + fix

_2026-07-14. m3. Trigger: Johan — "the sync wiped my Wave 2 settings — shouldn't happen."_
_Scope: which agency settings the live→qa1 nightly sync's reseed/preserve misses, and the fix._

## How the sync can wipe a setting (two mechanisms)
The nightly `sync-from-live.sh` drops all qa1 tables, loads a live snapshot, `migrate --force`s the
ahead-migrations, sanitises, then **reseeds** deterministic config + **restores** a preserve-snapshot.
A qa1 setting is lost if it survives neither reseed nor preserve. Two distinct ways it happens:

1. **Not-yet-live TABLE (LIVE-NO).** Live's dump lacks it; PHASE-2d drop + `migrate --force` re-creates
   it **empty**. Any qa1 rows gone.
2. **Live-schema TABLE holding qa1-only config (LIVE-yes, feature not configured on live).** The live
   load overwrites qa1 with live's rows (empty/default, because live agencies never configured the
   not-yet-surfaced feature). A `firstOrCreate` reseed only re-lays **DEFAULTS**, not Johan's values.

Mechanism 2 is the sneaky one and is the reported Wave 2 wipe.

## Method
Enumerated every agency-scoped settings/config table (migrations `create_*settings/_rule/compliance/
_matrix`), then checked each against **live** (`Schema::hasTable` on the live connection, read-only) and
against the sync's current PRESERVE set + `QaConfigSeeder`.

- **Preserve (current):** `sessions`, `agency_service_providers`, `agency_service_provider_contacts`.
- **Reseed (current, `QaConfigSeeder`):** `DealPipelineTemplateSeeder`, `DealStageDocumentRuleSeeder`,
  `DocumentDistributionMatrixSeeder`, `AgencyDealSyncSettings::forAgency` **pre-warm (defaults only)**.

## Findings — the full list

| Table | On live? | Covered before? | Verdict |
|---|---|---|---|
| **`agency_deal_sync_settings`** (DR2 Wave 2 cascade: flag-under-offer / sold-milestone / revert-on-declined / distribution-size-limit) | **yes** | pre-warm DEFAULTS only | **MISSED (mech. 2)** — Johan's toggles reverted every sync → **PRESERVE** |
| **`agency_proforma_settings`** (prefix, next-number SEQUENCE, due rule, bank details) | **no** | none | **MISSED (mech. 1)** — re-created empty every sync → **PRESERVE** |
| `document_distribution_matrix` (AT-227 null-stage rules) | no | `DocumentDistributionMatrixSeeder` | Covered (deterministic reseed) ✔ |
| `deal_stage_document_rules` (§8.1 matrix) | yes | `DealStageDocumentRuleSeeder` | Covered (deterministic reseed) ✔ |
| `deal_pipeline_templates`/`_steps` | yes | `DealPipelineTemplateSeeder` | Covered ✔ |
| `agency_document_type_compliance` (Save-To / FICA routing) | yes | — | Live-sourced & live-surfaced (AT-105 shipped) → syncing live's values is correct, not a miss |
| `agency_map_settings`, `comms_thread_settings`, `agency_contact_settings`, `agency_leave_visibility_matrix`, `agency_document_type_configs`, `performance_settings`, `branch_settings`, `rental_reminder_settings`, `calendar_event_class_settings`, `pp_event_feed_settings`, `automation_rules`, `property_setting_items`, `dev_settings` | yes | — | Live-schema **and** live-configured → live is the source of truth; syncing them is intended, not a miss |

**Net: exactly two tables are silently wiped — `agency_deal_sync_settings` and `agency_proforma_settings`.**
Both are human-tuned config with no canonical spec default → the **PRESERVE** bucket (not reseed; a
`firstOrCreate` reseed is precisely what erased Johan's values).

## Fix applied (`scripts/qa1/sync-from-live.sh`)
- `PRESERVE_TABLES` += `agency_deal_sync_settings agency_proforma_settings`. PHASE-2c snapshots qa1's
  real values before the load; PHASE-5b restores them after sanitise (`--add-drop-table`, so it wins
  over the live-loaded/empty version). The existing `forAgency` pre-warm then no-ops (rows already there).
- Pre/post row counts added for both tables → the evidence pack proves they survived
  (`deal-sync settings X→X`, `proforma settings X→X`).
- README class rule updated with mechanism 2 (the live-schema-but-unconfigured trap).

## Verification
- `bash -n sync-from-live.sh` — clean.
- No hard proof-gate added for these (a false-fail leaves qa1 DOWN); the evidence line is the right level.
- Ordering proven safe: PRESERVE restore precedes the `QaConfigSeeder` pre-warm, and preserve uses
  `--add-drop-table`, so qa1's values overwrite the live-loaded rows; FK-safe (mysqldump header sets
  `FOREIGN_KEY_CHECKS=0`; agency ids match live).
- Effective **tonight** — the cron runs the patched file on disk (`0 2 * * 1`).
