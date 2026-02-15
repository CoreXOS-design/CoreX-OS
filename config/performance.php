<?php

return [
    // Convert planned sales value into expected income for branch budget comparison
    // Tune these later to match your real model.
    'commission_rate' => env('HFC_COMMISSION_RATE', 0.05), // 5%
    'company_share'   => env('HFC_COMPANY_SHARE', 0.50),   // 50% kept by company
];
