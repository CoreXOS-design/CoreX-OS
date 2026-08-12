# CMA / Deeds Capture — spec (phase 1)

**Status:** Built (QA1) — 2026-08-12. Lead lane: cc1 (CoreX side). cc5 builds the Chrome extension against §2.

## 1. What it is
A one-click "ingest into CoreX" from a third-party CMA / deeds lookup page (CMA Info,
cmainfo.co.za) that shows a property's deeds data + owner identity. Shared plumbing with
prospecting (tracked_properties + TrackedPropertyMatchOrCreateService), but a SEPARATE
user-facing screen ("Deeds Capture"). Deeds captures are filtered OUT of MIC Opportunities.

Two plays: (1) prospecting from MIC — capture → suspense → Pitch Now promotes;
(2) own stock — capture → confirm on the Deeds Capture screen → create property + owner.

## 2. Capture endpoint + payload contract (for cc5)

```
POST /api/v1/deeds-capture
Authorization: Bearer <sanctum personal access token>   (same token flow as portal-capture)
Content-Type: application/json

{
  "source": "cmainfo",
  "captures": [                          // batch; 1+ per request
    {
      "source_ref": "cmainfo:<stable-id>",   // REQUIRED — idempotency + match-or-create source ref (stable per property)
      "property": {
        "deeds_office": null, "scheme_name": null, "scheme_number": null, "section_number": null,
        "erf_number": null, "address": null, "street_number": null, "street_name": null,
        "unit_number": null, "complex_name": null,
        "suburb": null,            // primary match key
        "municipality": null,      // stored as tracked_properties.town
        "province": null, "latitude": null, "longitude": null,
        "section_extent_m2": null, // stored as cadastral_extent
        "property_type": null, "title_deed_number": null
      },
      "owner": {
        "name": "John Smith",      // REQUIRED — person or company name
        "id_number": null,         // SA ID or company reg — THE JOIN KEY (phase-2 phone fill keys on it)
        "id_type": "sa_id"         // "sa_id" | "company_reg" | null
      },
      "sale": {
        "sale_price": null, "sale_date": null, "registered_date": null,
        "bond_holder": null, "bond_amount": null, "sale_type": null
      }
    }
  ]
}

Response 200: { "ok": true, "results": [ { "source_ref", "tracked_property_id", "owner_contact_id", "created" } ] }
Errors: 401 unauthenticated · 422 validation · per-row failure → results[].error (batch never hard-fails on one row)
```

## 3. Storage mapping
- tracked_property (match-or-create, source 'deeds_capture'): address parts, suburb, town(=municipality),
  province, lat/lng, erf_number, title_deed_number, cadastral_extent(=section_extent_m2), property_type,
  last_known_sold_price(=sale_price), last_known_sold_date(=sale_date). New columns: deeds_office, scheme_name,
  scheme_number, section_number, bond_holder, bond_amount, sale_type, deeds_registered_date, capture_kind.
- `capture_kind='deeds_capture'` is stamped ONLY when the deeds capture CREATES the tracked_property (deeds data
  enriching an existing prospecting TP leaves it in Opportunities).
- owner → contacts (name + id_number + id_type; phone LEFT EMPTY), deduped on id_number; linked via
  tracked_properties.owner_contact_id. On promote → contact_property role='owner'.
- contacts.id_type = 'sa_id' | 'company_reg'; the owner ID always lives in contacts.id_number.

## 4. Screen
`GET /corex/deeds-capture` (permission `deeds_capture.access`, own sidebar item) — lists un-promoted deeds
captures; `POST /corex/deeds-capture/{trackedProperty}/promote` → promoteToStock + owner link. Phone-fill for
the Virtual Agent is a placeholder (phase 2).

## 5. Deliberately NOT in phase 1
Virtual Agent phone-fill (phase 2), market_data_points metric history for sale/bond, a per-field edit/confirm UI.
