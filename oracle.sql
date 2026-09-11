-- INDEPENDENT ORACLE — Buyers Report verification
-- Period P1 (primary, chosen because it's the only window with real non-zero
-- data for every metric in QA1's current seed): 2026-06-01 00:00:00 (incl.)
-- to 2026-08-21 00:00:00 (excl.), SAST wall-clock (confirmed DB stores SAST
-- directly, matches MySQL NOW() = host wall clock, no UTC conversion needed).
SET @p_start = '2026-06-01 00:00:00';
SET @p_end   = '2026-08-21 00:00:00';

-- =========================================================================
-- 0. SANITY: which agency has buyers at all (confirms single-agency test set)
-- =========================================================================
SELECT 'sanity_distinct_agencies_with_buyers' AS check_name, GROUP_CONCAT(DISTINCT agency_id) AS val
FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL;

-- =========================================================================
-- 1. BUYERS HELD — current snapshot (not period-scoped; no agent-history
--    table exists to reconstruct a past assignment, so "held" = current
--    agent_id/branch_id roster, regardless of buyer_state).
-- =========================================================================
SELECT 'held_per_agent' AS metric, agent_id, COUNT(*) AS n
FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL
GROUP BY agent_id ORDER BY agent_id;

SELECT 'held_per_branch' AS metric, branch_id, COUNT(*) AS n
FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL
GROUP BY branch_id ORDER BY branch_id;

SELECT 'held_agency_total' AS metric, COUNT(*) AS n
FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL;

SELECT 'held_sum_per_agent_rows' AS metric, SUM(n) AS n FROM (
  SELECT COUNT(*) AS n FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL AND agent_id IS NOT NULL GROUP BY agent_id
) x;

SELECT 'held_unassigned_agent_null' AS metric, COUNT(*) AS n
FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL AND agent_id IS NULL;

-- =========================================================================
-- 2. BUYERS ADDED — period-scoped on buyer_pipeline_entered_at, CURRENT
--    agent_id/branch_id attribution (no at-add snapshot columns exist).
-- =========================================================================
SELECT 'added_per_agent' AS metric, agent_id, COUNT(*) AS n
FROM contacts
WHERE is_buyer=1 AND deleted_at IS NULL
  AND buyer_pipeline_entered_at >= @p_start AND buyer_pipeline_entered_at < @p_end
GROUP BY agent_id ORDER BY agent_id;

SELECT 'added_per_branch' AS metric, branch_id, COUNT(*) AS n
FROM contacts
WHERE is_buyer=1 AND deleted_at IS NULL
  AND buyer_pipeline_entered_at >= @p_start AND buyer_pipeline_entered_at < @p_end
GROUP BY branch_id ORDER BY branch_id;

SELECT 'added_agency_total' AS metric, COUNT(*) AS n
FROM contacts
WHERE is_buyer=1 AND deleted_at IS NULL
  AND buyer_pipeline_entered_at >= @p_start AND buyer_pipeline_entered_at < @p_end;

-- =========================================================================
-- 3. BUYERS WON — period-scoped via buyer_state_transitions.to_state='won',
--    attributed via the contact's CURRENT agent_id/branch_id (no at-won
--    snapshot exists, unlike lost — flagged as an asymmetry in the report).
-- =========================================================================
SELECT 'won_per_agent' AS metric, c.agent_id, COUNT(*) AS n
FROM buyer_state_transitions t
JOIN contacts c ON c.id = t.contact_id
WHERE t.to_state='won' AND t.occurred_at >= @p_start AND t.occurred_at < @p_end
  AND c.deleted_at IS NULL
GROUP BY c.agent_id ORDER BY c.agent_id;

SELECT 'won_per_branch' AS metric, c.branch_id, COUNT(*) AS n
FROM buyer_state_transitions t
JOIN contacts c ON c.id = t.contact_id
WHERE t.to_state='won' AND t.occurred_at >= @p_start AND t.occurred_at < @p_end
  AND c.deleted_at IS NULL
GROUP BY c.branch_id ORDER BY c.branch_id;

SELECT 'won_agency_total' AS metric, COUNT(*) AS n
FROM buyer_state_transitions t
JOIN contacts c ON c.id = t.contact_id
WHERE t.to_state='won' AND t.occurred_at >= @p_start AND t.occurred_at < @p_end
  AND c.deleted_at IS NULL;

-- Duplicate-transition check: can a contact have >1 'won' transition (would
-- double count if the report doesn't dedupe by contact)?
SELECT 'won_duplicate_contacts' AS metric, COUNT(*) AS n FROM (
  SELECT contact_id FROM buyer_state_transitions
  WHERE to_state='won' AND occurred_at >= @p_start AND occurred_at < @p_end
  GROUP BY contact_id HAVING COUNT(*) > 1
) x;

-- =========================================================================
-- 4. BUYERS LOST + VALUE LOST — period-scoped via buyer_lost_records.
--    recorded_at, attributed via the AT-LOSS snapshot columns (the schema
--    explicitly captured historical attribution here, unlike won/added).
--    NULL preapproval_amount_at_loss treated as 0, never dropped.
-- =========================================================================
SELECT 'lost_per_agent' AS metric, agent_owner_user_id_at_loss AS agent_id,
       COUNT(*) AS n, SUM(COALESCE(preapproval_amount_at_loss,0)) AS value_lost
FROM buyer_lost_records
WHERE recorded_at >= @p_start AND recorded_at < @p_end
GROUP BY agent_owner_user_id_at_loss ORDER BY agent_owner_user_id_at_loss;

SELECT 'lost_per_branch' AS metric, branch_id_at_loss AS branch_id,
       COUNT(*) AS n, SUM(COALESCE(preapproval_amount_at_loss,0)) AS value_lost
FROM buyer_lost_records
WHERE recorded_at >= @p_start AND recorded_at < @p_end
GROUP BY branch_id_at_loss ORDER BY branch_id_at_loss;

SELECT 'lost_agency_total' AS metric, COUNT(*) AS n,
       SUM(COALESCE(preapproval_amount_at_loss,0)) AS value_lost
FROM buyer_lost_records
WHERE recorded_at >= @p_start AND recorded_at < @p_end;

SELECT 'lost_recovered_within_period' AS metric, COUNT(*) AS n
FROM buyer_lost_records
WHERE recorded_at >= @p_start AND recorded_at < @p_end AND recovered_at IS NOT NULL;

-- =========================================================================
-- 5. APPOINTMENTS HELD WITH BUYERS — period-scoped on event_date,
--    status='completed' (the only value that means "actually happened" in
--    this schema — buyer_facing flag on calendar_event_class_settings is
--    NOT usable, see finding below), contact must be an active buyer,
--    attributed via the contact's CURRENT agent_id (cross-checked against
--    calendar_events.user_id separately).
-- =========================================================================
SELECT 'appts_per_agent_via_contact' AS metric, c.agent_id, COUNT(*) AS n
FROM calendar_events ce
JOIN contacts c ON c.id = ce.contact_id
WHERE ce.status='completed' AND ce.deleted_at IS NULL
  AND ce.event_date >= @p_start AND ce.event_date < @p_end
  AND c.is_buyer=1 AND c.deleted_at IS NULL
GROUP BY c.agent_id ORDER BY c.agent_id;

SELECT 'appts_per_agent_via_event_user' AS metric, ce.user_id, COUNT(*) AS n
FROM calendar_events ce
JOIN contacts c ON c.id = ce.contact_id
WHERE ce.status='completed' AND ce.deleted_at IS NULL
  AND ce.event_date >= @p_start AND ce.event_date < @p_end
  AND c.is_buyer=1 AND c.deleted_at IS NULL
GROUP BY ce.user_id ORDER BY ce.user_id;

SELECT 'appts_agent_vs_event_user_mismatch' AS metric, COUNT(*) AS n
FROM calendar_events ce
JOIN contacts c ON c.id = ce.contact_id
WHERE ce.status='completed' AND ce.deleted_at IS NULL
  AND ce.event_date >= @p_start AND ce.event_date < @p_end
  AND c.is_buyer=1 AND c.deleted_at IS NULL
  AND (ce.user_id IS NULL OR ce.user_id != c.agent_id);

SELECT 'appts_agency_total' AS metric, COUNT(*) AS n
FROM calendar_events ce
JOIN contacts c ON c.id = ce.contact_id
WHERE ce.status='completed' AND ce.deleted_at IS NULL
  AND ce.event_date >= @p_start AND ce.event_date < @p_end
  AND c.is_buyer=1 AND c.deleted_at IS NULL;

-- Alternate definition using the codified category list from the CRM
-- foundation migration's own backfill logic (viewing/listing_presentation/
-- property_evaluation) instead of status='completed', for comparison —
-- since buyer_facing is broken agency-wide.
SELECT 'appts_agency_total_via_category_list' AS metric, COUNT(*) AS n
FROM calendar_events ce
JOIN contacts c ON c.id = ce.contact_id
WHERE ce.deleted_at IS NULL AND ce.category IN ('viewing','listing_presentation','property_evaluation')
  AND ce.event_date >= @p_start AND ce.event_date < @p_end
  AND c.is_buyer=1 AND c.deleted_at IS NULL;

-- =========================================================================
-- 6. RETHA KELLY (user 24) — independent check of Johan's stated expectation
-- =========================================================================
SELECT 'retha_held' AS metric, COUNT(*) AS n FROM contacts WHERE is_buyer=1 AND deleted_at IS NULL AND agent_id=24;
SELECT 'retha_branch_id' AS metric, branch_id FROM users WHERE id=24;
SELECT 'retha_branch_held_total' AS metric, COUNT(*) AS n FROM contacts c JOIN users u ON u.id=24
WHERE c.is_buyer=1 AND c.deleted_at IS NULL AND c.branch_id = u.branch_id;

-- =========================================================================
-- 7. MIDNIGHT BOUNDARY CHECK — anything landing EXACTLY on a period edge
-- =========================================================================
SELECT 'boundary_events_at_p_start' AS metric, COUNT(*) AS n FROM calendar_events WHERE event_date = @p_start;
SELECT 'boundary_events_at_p_end' AS metric, COUNT(*) AS n FROM calendar_events WHERE event_date = @p_end;
SELECT 'boundary_added_at_p_start' AS metric, COUNT(*) AS n FROM contacts WHERE buyer_pipeline_entered_at = @p_start;
SELECT 'boundary_won_at_p_end' AS metric, COUNT(*) AS n FROM buyer_state_transitions WHERE occurred_at = @p_end;
