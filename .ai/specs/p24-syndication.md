# Spec: Property24 Outbound Syndication (ExDev REST API v53)

**Status:** Built — Phase 1
**Approved by:** Johan (2026-03-26)

## Overview

Push CoreX property listings to Property24 via their ExDev REST API (Listing Service v53). Completely separate from Private Property SOAP syndication. No PP code is touched.

## API Details

- **Base URL:** `https://api.exdev.property24-test.com`
- **Auth:** HTTP Basic Auth (username:password)
- **Listing endpoint:** `POST /listing/v53/listings` (create and update)
- **Status endpoint:** `PUT /listing/v53/listings/{listingNumber}/status`
- **Images:** Sent as base64-encoded bytes inline (not URLs)
- **Suburb:** Requires P24 `suburbId` (integer from `/listing/v53/suburbs`)
- **Property Type:** Requires P24 `propertyTypeId` (integer from `/listing/v53/property-types`)

## Files

### Services
- `app/Services/Syndication/Property24/Property24ApiClient.php`
- `app/Services/Syndication/Property24/Property24ListingMapper.php`
- `app/Services/Syndication/Property24/Property24SyndicationService.php`

### Controller
- `app/Http/Controllers/Property24/P24SyndicationController.php`

### Jobs
- `app/Jobs/SubmitListingToProperty24.php`
- `app/Jobs/SyncProperty24Activations.php`

### Commands
- `app/Console/Commands/P24SyndicateSubmit.php` — `p24:syndicate-submit {property}`
- `app/Console/Commands/P24SyndicateSmokeTest.php` — `p24:syndicate-smoke-test`

### Model
- `app/Models/P24SyndicationLog.php`

### Migration
- `database/migrations/2026_03_26_100001_add_p24_syndication_columns_to_properties_table.php`

## Credentials (.env)

```
P24_EXDEV_API_URL=https://api.exdev.property24-test.com
P24_EXDEV_USERNAME=31357@hfcoastal.co.za
P24_EXDEV_PASSWORD=PrOp3rt13s2o26
P24_EXDEV_AGENCY_ID=31357
P24_EXDEV_SANDBOX=true
P24_EXDEV_IMAGE_BASE_URL=https://corex.hfcoastal.co.za
```
