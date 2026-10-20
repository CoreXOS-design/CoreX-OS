<?php

/**
 * Abuse protection for the public, token-gated rental links (contractor job link, crew job link, crew page, tenant completion link) - audit fix C-D2.
 * Not agency settings: they protect the server, they are not an agency's business rule. The limits are per TOKEN (the person holding the link), so a crew on one
 * office or mobile-network address no longer shares one bucket with every other crew; a second, much larger per-address ceiling stops probing with made-up tokens.
 */
return [
    // ordinary actions (open, tick, send, answer ...) - per token, per minute
    'per_token_per_minute' => (int) env('RENTAL_LINK_PER_TOKEN_PER_MINUTE', 30),
    // photo uploads and line add/change/undo - per token, per 10 minutes (replaces, never adds to, the ordinary limit on those routes)
    'heavy_per_token_per_ten_minutes' => (int) env('RENTAL_LINK_HEAVY_PER_TOKEN_PER_TEN_MINUTES', 60),
    // every public link request from one address, per minute (all tokens together)
    'per_address_per_minute' => (int) env('RENTAL_LINK_PER_ADDRESS_PER_MINUTE', 300),
    // requests with a token that matches no issued link (a made-up token) from one address, per minute - the guard against guessing tokens (audit 2026-10-20 finding 9).
    // Far lower than the ceiling above on purpose: a real crew holds real tokens and never makes a miss.
    'misses_per_address_per_minute' => (int) env('RENTAL_LINK_MISSES_PER_ADDRESS_PER_MINUTE', 20),
];
