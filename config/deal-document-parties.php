<?php

/*
 | Which side of a deal a filed document belongs to.
 |
 | A document filed from a deal (DealDocumentService::fileDealDocumentFromDeal) is linked to the
 | DEAL'S OWN parties (deal_contacts) only — never to everyone linked to the property. This file
 | says which of them: a seller-side document goes to the deal's sellers, a buyer-side document to
 | its buyers, anything else (OTP, sale agreement, attorney correspondence, proformas, work
 | authorisations, unknown) to both. See .ai/specs/contacts.md "Deal documents reach the deal's
 | parties only".
 |
 | Order of decision: an explicit `party_side` from the caller, then the document-type slug below,
 | then the file name, then both. Add a slug here to change where a type goes.
 */
return [
    'seller_slugs' => ['mandate', 'mandate_extension', 'mandate_price_reduction', 'listing_form', 'disclosure'],
    'buyer_slugs'  => ['bond_grant'],

    // Words in the file name that settle a side for types that belong to either party
    // (FICA, IDs, proof of residence, bank statements …). Both present, or neither → both parties.
    'seller_words' => ['seller', 'vendor'],
    'buyer_words'  => ['purchaser', 'buyer'],
];
