## 9. Required amendment to `.ai/specs/rental-inspections.md` — not applied yet, named here

This spec does not silently rewrite the already-committed inspections spec. The following change is
what's needed once Johan has reviewed this document, to be applied as its own small follow-up:

### 9.1 The change

`rental_inspections` (§3.2 of the inspections spec) gains a required `lease_id` FK. `property_id` is
kept as a denormalized convenience column (always set to `lease.property_id` at creation, never edited
independently) so simple "every inspection on this property" queries don't require a join — but
`lease_id` becomes the authoritative answer to "which tenancy did this inspection belong to."
`rental_inspection_items` (the physical spaces/meters) are **unchanged — still property-scoped, not
lease-scoped** — a "Bedroom 1" is a physical fact about the property that outlives any one tenancy.

### 9.2 Why this doesn't break the carry-forward requirement — argued, because it looked like a
contradiction at first

Johan's inspections ruling wants an out-inspection to carry forward a fault reported "2 years ago" so
an owner can't blame a tenant for damage the owner never fixed. Read literally, this could mean
carry-forward evidence needs to span across DIFFERENT tenants' leases, not just one lease's own
history — which could look like it contradicts wanting inspections scoped to a specific lease at all.

**It doesn't, once items and inspections are split the way §9.1 describes.** Because
`rental_inspection_items` stay property-scoped while `rental_inspections` become lease-scoped, the
carry-forward query ("show every observation ever made on this physical item, regardless of which
lease it happened under") is a simple join — item → observations → inspections → leases — and it
naturally spans every tenant the property has ever had. At the same time, "which tenant did THIS
specific inspection event belong to" stays unambiguous, because the inspection event itself carries
`lease_id` directly. Both of Johan's requirements — cross-tenant evidence, and per-tenant attribution
— are satisfied simultaneously, not in tension, once the item/inspection split is in place. This is
the reasoning that justifies doing leases first at all, made explicit.

---
