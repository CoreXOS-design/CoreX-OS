<?php

/*
 * Road to Sold values that are NOT agency settings: how the engine reads data, not how an agency works.
 * (road-to-sold.md section 21: "Properties read per window" left the settings screen, 27 Oct 2026.)
 */
return [
    // How many Market Intelligence properties the call list reads in one window.
    'scan_page_size' => (int) env('RTS_SCAN_PAGE_SIZE', 200),
];
