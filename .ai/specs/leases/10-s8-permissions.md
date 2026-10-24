## 8. Permissions

New keys in `config/corex-permissions.php`, following the `rental_applications.*`/
`rental_inspections.*` naming convention:
- `leases.view`
- `leases.create` (covers drafting, activating, linking tenants)
- `leases.renew` — separate from `.create` **[cc5 design call, flagged for Johan]**, since renewal
  changes the property's active lease and touches the overlap-prevention guard (§3.5); if this
  distinction is unwanted, collapsing it into `.create` is a one-line change at build time.
- `leases.cancel`

---
