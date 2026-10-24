## 4. Attachment points — what this spec exists to enable

Named here, not built:

- **In-inspection / out-inspection** (`.ai/specs/rental-inspections.md`) — a `rental_inspections` row
  gets a required `lease_id`. See §9 for the exact amendment this requires against the already-written
  inspections spec, and the reasoning for why items stay property-scoped while inspections become
  lease-scoped.
- **Work orders** (future spec, not yet written) — a future `rental_work_orders` row should carry
  `lease_id` (which tenancy this repair happened under) in addition to the `rental_inspection_item_id`
  link already reasoned about in the inspections spec's §3.4 (which physical space/item). Both matter:
  the item tells you WHAT broke and its full cross-tenancy history; the lease tells you WHO was living
  there and under what agreement when it broke. Naming this now so the work-orders spec has both FKs
  to reach for.

---
