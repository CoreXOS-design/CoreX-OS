## 2. The core (kept small, deliberately)

A lease takes a **Property** (owner already known via the existing owner/landlord contact link —
`Property::sellerOwnerContact()`, `contact_property` pivot roles `owner`/`landlord`/`lessor`, already
built and out of scope to respec here), adds **one or more Tenants**, and carries **terms**. That's it.
Everything else in this spec exists to support that core correctly, not to expand it.

```
leases
  id
  agency_id              -- BelongsToAgency
  branch_id
  property_id             -- FK properties, REQUIRED (this is the entire point of the spine)
  status                  -- enum: 'draft' | 'active' | 'expired' | 'cancelled'
  rental_amount            -- decimal, THIS lease's agreed rent — distinct from
                           --   properties.rental_amount, which is the property's advertised/
                           --   asking rent. Naming them the same thing in two places is exactly
                           --   the kind of confusion this spec exists to remove — see §6.
  deposit_amount            -- nullable decimal (§4)
  start_date               -- required
  end_date                  -- nullable (month-to-month has no fixed end — see is_month_to_month)
  is_month_to_month         -- bool
  lease_type                -- reuses the existing enum shape from properties.lease_type
                            --   (Net/Gross/Modified Gross/Percentage) for consistency, not
                            --   reinvented
  source                    -- enum: 'manual' | 'rental_application' | 'esign_document' — audit
                            --   trail of how this lease came to exist
  rental_application_id      -- nullable FK, set when source='rental_application'
  source_document_id         -- nullable FK to docuperfect_documents, set when
                             --   source='esign_document' (§1.2 — reserved for when the
                             --   extraction is rebuilt correctly; not required to be populated
                             --   on day one)
  previous_lease_id          -- nullable, self-referencing FK — see §3 (renewal chain)
  renewed_lease_id            -- nullable, self-referencing FK — the inverse pointer, set once
                              --   THIS lease has been renewed into a new one
  created_by_user_id
  cancelled_at, cancelled_by_user_id, cancel_reason   -- nullable, set only for status='cancelled'
  created_at, updated_at, deleted_at   -- soft-delete, gated exactly per §3.3 of
                                       --   rental-inspections.md's own reasoning (same author,
                                       --   same principle): deletable only while NOTHING has
                                       --   attached to it yet (no inspection, no escalation, no
                                       --   work order). Once evidence exists, only 'cancelled' —
                                       --   never destroyed.

lease_tenants                -- joint tenants, N-party, per Johan's e-sign doctrine (§3.2)
  id
  lease_id
  contact_id
  is_primary                -- bool — which tenant is the primary correspondence contact;
                             --   does not imply the others have lesser standing on the lease
  created_at
  UNIQUE(lease_id, contact_id)

lease_escalations             -- append-only rate history, §5
  id
  lease_id
  effective_date
  previous_rental_amount        -- snapshotted, not re-derived later
  new_rental_amount
  escalation_rate_percent        -- computed AND stored at entry time (see §5 reasoning)
  note
  created_by_user_id
  created_at                     -- immutable, no updates, no deletes — same evidence-integrity
                                 --   reasoning as rental_inspection_observations

lease_settings                  -- one row per agency, §8
  id, agency_id (unique)
  expiry_notice_window_days       -- nullable, PENDING legal confirmation — see §8. Do not
                                  --   default this to a specific number of weeks; leave null
                                  --   (meaning "no automatic notice yet") until Johan's
                                  --   compliance officer confirms the real figure.
  created_at, updated_at
```

---
