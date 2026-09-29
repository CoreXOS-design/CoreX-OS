# Spec: Deals

**Status:** Partially live — full consolidation required

---

## What a Deal Is

A Deal is the transaction record — the thing that links a property being sold or rented to the parties involved, the documents generated, the compliance requirements, and the commission earned. It is a core pillar.

Every sale and every rental must have a Deal record. No commission can be calculated without a linked Deal.

---

## Consolidation Items (Phase 1)

- [ ] Deal record holds all parties — buyer + seller for sales, landlord + tenant for rentals
- [ ] Deal record links to Property
- [ ] Deal record links to Agent(s)
- [ ] FICA flag visible on Deal record
- [ ] Commission record links to Deal (not standalone)
- [ ] All deal types and pipeline statuses from settings table

---

## Pending Spec Items

- Sales pipeline view (visual: mandate → OTP → registered)
- Rental pipeline view (visual: mandate → lease → active → renewal)
- Deal-to-Flow integration (deal creation and progression via flows)
- Deal history per property (full transaction audit trail)

---

*Full spec to be completed during Phase 1 consolidation sprint.*
