<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityTarget extends Model
{
    protected $fillable = [
        'period',
        'user_id',
        'branch_id',

        'calls_made_target',
        'doors_knocked_target',
        'whatsapps_sent_target',
        'referrals_asked_target',
        'flyers_dropped_target',

        'presentations_booked_target',
        'presentations_done_target',

        'oats_signed_target',
        'eats_signed_target',

        'buyer_leads_target',
        'seller_leads_target',
        'portal_leads_target',
        'referral_leads_target',

        'buyer_appointments_target',

        'otps_written_target',
        'otps_accepted_target',
        'otps_collapsed_target',

        'notes',

        'created_by',
        'updated_by',
    ];
}
