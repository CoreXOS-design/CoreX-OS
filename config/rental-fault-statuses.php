<?php

/**
 * Rentals - the ONE name for each fault-report status (Johan, 9 Oct 2026, C2: "one fault status has three names"). The tiles, the status filter,
 * the list badge, the detail badge, the print list, the CSV/Excel export, the report buckets and the PDF all read these words through
 * `RentalFaultReport::statusWord()` / `statusLabel()`. Change a word here and every screen follows. (The owner's and tenant's portal screens keep
 * their own plain wording - `ownerStatusLabel()` and the tenant's neutral progress line.)
 */
return [
    'reported' => 'Reported',
    'under_review' => 'Under agent review',
    'awaiting_approval' => 'Sent to owner',
    'approved' => 'Owner approved',
    'declined' => 'Owner declined',
    'owner_handling' => 'Owner arranges the repair',
    'work_order_raised' => 'Work order raised',
    'resolved' => 'Resolved',
    'cancelled' => 'Cancelled',
];
