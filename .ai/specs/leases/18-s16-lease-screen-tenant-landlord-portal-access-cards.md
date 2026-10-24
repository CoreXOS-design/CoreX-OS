## 16. Lease screen — Tenant / Landlord portal access cards (7 Oct 2026, QA1)
The two cards at the foot of the lease screen's right panel show, per tenant / landlord of THIS lease, the portal
login's status and the personal portal link with Copy, Email link / Resend invite and WhatsApp share, and one
"Set up portal access & email the link" action when there is no login. A signed lease gives its parties portal
access automatically (agency setting, default ON, in the Setup Wizard) and its signed-copy email carries their
link. Behaviour, rules and tests: `.ai/specs/rental-portal-access.md` §16. View: `corex.leases._portal-access-person`.

Paper-signed copy (7 Oct 2026): attaching a signed paper copy (New Lease, Renewal, renewal upload, APIs) also emails the tenant(s) and
landlord(s) the copy with their portal link, once per lease — `.ai/specs/rental-portal-access.md` §18.

Portal (7 Oct 2026): a signed lease's agreement (e-signed final PDF or the wet-ink copy) is listed in the tenant's and owner's portal Documents
area; unsigned and archived leases never are — `.ai/specs/rental-portal-access.md` §19.
