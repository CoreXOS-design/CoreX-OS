<?php

namespace App\Console\Commands\Leave;

use App\Models\Leave\LeaveApplication;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Console\Command;

class SendLeaveRemindersCommand extends Command
{
    protected $signature = 'corex:leave:send-reminders';
    protected $description = 'Send leave starting/ending reminders to employees';

    public function handle(): int
    {
        $dispatcher = app(NotificationDispatcher::class);
        $startingSoon = 0;
        $endingToday = 0;

        // Leave starting in 3 days
        $threeDaysAhead = now()->addDays(3)->toDateString();
        $startingApps = LeaveApplication::where('status', 'approved')
            ->whereDate('start_date', $threeDaysAhead)
            ->with('user', 'leaveType')
            ->get();

        foreach ($startingApps as $app) {
            try {
                $dispatcher->fire($app->user, 'leave.starting_soon', $app, [
                    'title' => "Leave starting in 3 days",
                    'body'  => "Your {$app->leaveType->label} ({$app->working_days_requested} days) starts on {$app->start_date->format('d M Y')}.",
                    'subject_label' => $app->application_number,
                    'action_url'    => route('my-portal.leave.show', $app),
                    'severity'      => 'info',
                    'threshold_hit_at' => now()->startOfDay(),
                ]);
                $startingSoon++;
            } catch (\Throwable $e) {
                $this->error("Failed for {$app->application_number}: {$e->getMessage()}");
            }
        }

        // Leave ending today
        $today = now()->toDateString();
        $endingApps = LeaveApplication::where('status', 'approved')
            ->whereDate('end_date', $today)
            ->with('user', 'leaveType')
            ->get();

        foreach ($endingApps as $app) {
            try {
                $dispatcher->fire($app->user, 'leave.ending_soon', $app, [
                    'title' => "Leave ends today",
                    'body'  => "Your {$app->leaveType->label} ends today. Welcome back tomorrow!",
                    'subject_label' => $app->application_number,
                    'action_url'    => route('my-portal.leave.show', $app),
                    'severity'      => 'info',
                    'threshold_hit_at' => now()->startOfDay(),
                ]);
                $endingToday++;
            } catch (\Throwable $e) {
                $this->error("Failed for {$app->application_number}: {$e->getMessage()}");
            }
        }

        $this->info("[SUCCESS] Sent {$startingSoon} starting-soon + {$endingToday} ending-today reminders.");

        return self::SUCCESS;
    }
}
