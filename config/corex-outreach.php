<?php

/**
 * Seller-outreach / marketing-consent configuration (AT-50).
 *
 * The "live transaction" definition used to gate transactional opt-out:
 * a contact is in a live sale when they are a party on a deals_v2 row whose
 * status is one of `live_deal_statuses` AND whose actual_registration is NULL
 * (a registered deal is concluded regardless of status), linked via a
 * deal_v2_contacts.role in `transaction_party_roles`.
 *
 * `live_deal_statuses` is the SYSTEM DEFAULT — each agency may override it via
 * agencies.outreach_live_deal_statuses (see Agency::liveDealStatuses()). It is
 * never hardcoded in the service.
 */
return [
    // deals_v2.status values that count as a live (in-progress) transaction.
    'live_deal_statuses' => ['active'],

    // deal_v2_contacts.role values that make a contact a transaction party
    // whose business comms must continue while the deal is live.
    'transaction_party_roles' => ['seller', 'co_seller', 'buyer', 'co_buyer'],
];
