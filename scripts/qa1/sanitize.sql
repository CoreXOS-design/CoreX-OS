-- qa1/sanitize.sql — run against corex_qa1 IMMEDIATELY after loading a live snapshot.
-- Purpose: make the copied-from-live data safe on the disposable qa1 box.
-- Idempotent: safe to re-run. Never run against a production database.
--
-- The qa1 .env already neuters every SEND CHANNEL (mail→Mailpit, WAHA empty,
-- P24_IMPORT_ENABLED=false, PP sandbox). This file neutralises the DATA that a
-- live snapshot drags in and that .env cannot reach.

-- 1) PURGE live-intended queued work — the belt-and-suspenders the gate proves.
--    QUEUE_CONNECTION=database, so these tables carry real pending sends from live.
TRUNCATE TABLE jobs;
TRUNCATE TABLE job_batches;
TRUNCATE TABLE failed_jobs;

-- 2) Neuter WhatsApp devices copied from live (no live session/token usable from qa1).
UPDATE communication_wa_devices
   SET active = 0, waha_session = NULL, device_token = NULL
 WHERE active = 1 OR waha_session IS NOT NULL OR device_token IS NOT NULL;

-- 3) Clear the PrivateProperty feed cursors so qa1 never resumes a live syndication cursor.
DELETE FROM pp_event_feed_settings WHERE `key` LIKE 'continuation_key%';

-- 4) Session / cache hygiene — drop live sessions + any cached config/queries.
--    Forces a clean re-login on qa1 and prevents stale live-cached values leaking in.
TRUNCATE TABLE sessions;
TRUNCATE TABLE cache;
TRUNCATE TABLE cache_locks;
