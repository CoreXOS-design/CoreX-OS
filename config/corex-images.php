<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hostnames CoreX serves its own images from
    |--------------------------------------------------------------------------
    |
    | Gallery photos are stored as URLs, and historically they were written in
    | whatever form the request that created them happened to produce. A live
    | audit (2026-07-09) found 22,729 gallery references in three shapes:
    |
    |     20,548  https://corexos.co.za/storage/properties/…   (canonical)
    |      2,129  /storage/properties/…                        (relative)
    |         23  https://corex.hfcoastal.co.za/storage/…      (alternate vhost)
    |         29  http://91.99.130.85/storage/…                (bare IP)
    |
    | All four are the SAME file on our own disk. PropertyImageGuard must treat
    | every one of them as local — a reference it can stat, and must therefore
    | validate — while treating a genuine portal mirror (images.prop24.com) as
    | external and passing it through untouched.
    |
    | Keying "local" off APP_URL alone silently exempts the 52 alternate-host
    | references from validation, which is exactly the check that stops a
    | dangling URL reaching PrivateProperty and killing the listing update.
    |
    | APP_URL's host is always included automatically; this list adds the others.
    | Override per environment with a comma-separated COREX_IMAGE_HOSTS.
    |
    */

    'local_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'COREX_IMAGE_HOSTS',
            'corexos.co.za,www.corexos.co.za,corex.hfcoastal.co.za,91.99.130.85'
        ))
    ))),

];
