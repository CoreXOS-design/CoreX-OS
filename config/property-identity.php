<?php

declare(strict_types=1);

return [
    /*
    | Property identity (.ai/specs/property-identity.md §7). When on, a property, CMA comparable row, presentation
    | sold comp or email alert saved through Eloquent is attached to its property file in the same request. The
    | test environment turns it off (phpunit.xml) so unrelated tests do not grow property files; the tests of this
    | feature turn it on explicitly.
    */
    'link_on_create' => (bool) env('PROPERTY_IDENTITY_LINK_ON_CREATE', true),
];
