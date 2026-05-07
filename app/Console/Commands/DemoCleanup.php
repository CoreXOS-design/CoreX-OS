<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoCleanup extends Command
{
    protected $signature = 'demo:cleanup {--force : Skip confirmation}';
    protected $description = 'Remove all demo-prefixed data seeded by demo:seed. Local only.';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Refusing — APP_ENV is not local.');
            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('This will delete ALL [DEMO]-prefixed records. Continue?')) {
            return self::SUCCESS;
        }

        $this->info('Cleaning up demo data...');

        // Get demo contact IDs first (needed for cascade)
        $demoContactIds = DB::table('contacts')
            ->where('first_name', 'like', '[DEMO]%')
            ->pluck('id')->toArray();

        // Get demo property IDs
        $demoPropertyIds = DB::table('properties')
            ->where('address', 'like', '[DEMO]%')
            ->pluck('id')->toArray();

        // Get demo event IDs
        $demoEventIds = DB::table('calendar_events')
            ->where('title', 'like', '[DEMO]%')
            ->pluck('id')->toArray();

        // Cascade: buyer data
        if (!empty($demoContactIds)) {
            $c = DB::table('buyer_preferences')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  buyer_preferences: {$c}");
            $c = DB::table('buyer_property_responses')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  buyer_property_responses: {$c}");
            $c = DB::table('buyer_activity_log')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  buyer_activity_log: {$c}");
            $c = DB::table('buyer_lost_records')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  buyer_lost_records: {$c}");
            $c = DB::table('buyer_lost_risk_scores')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  buyer_lost_risk_scores: {$c}");
            $c = DB::table('prospecting_buyer_matches')->whereIn('contact_id', $demoContactIds)->delete();
            $this->line("  prospecting_buyer_matches (by contact): {$c}");
        }

        // Cascade: property data
        if (!empty($demoPropertyIds)) {
            $c = DB::table('property_sold_records')->whereIn('property_id', $demoPropertyIds)->delete();
            $this->line("  property_sold_records: {$c}");
            $c = DB::table('property_marketing_activities')->whereIn('property_id', $demoPropertyIds)->delete();
            $this->line("  property_marketing_activities: {$c}");
            $c = DB::table('property_buyer_matches')->whereIn('property_id', $demoPropertyIds)->delete();
            $this->line("  property_buyer_matches: {$c}");
        }

        // Cascade: calendar data
        if (!empty($demoEventIds)) {
            $c = DB::table('calendar_event_feedback')->whereIn('calendar_event_id', $demoEventIds)->delete();
            $this->line("  calendar_event_feedback: {$c}");
            $c = DB::table('calendar_event_links')->whereIn('calendar_event_id', $demoEventIds)->delete();
            $this->line("  calendar_event_links: {$c}");
            $c = DB::table('calendar_event_invitations')->whereIn('event_id', $demoEventIds)->delete();
            $this->line("  calendar_event_invitations: {$c}");
        }

        // Delete main records
        $c = DB::table('contacts')->where('first_name', 'like', '[DEMO]%')->delete();
        $this->line("  contacts: {$c}");

        $c = DB::table('properties')->where('address', 'like', '[DEMO]%')->delete();
        $this->line("  properties: {$c}");

        $c = DB::table('calendar_events')->where('title', 'like', '[DEMO]%')->delete();
        $this->line("  calendar_events: {$c}");

        // Clean notifications from observer
        $c = DB::table('notifications')->where('type', 'prospecting_match_alert')->delete();
        $this->line("  prospecting_match_alert notifications: {$c}");

        $this->info('Demo cleanup complete.');
        return self::SUCCESS;
    }
}
