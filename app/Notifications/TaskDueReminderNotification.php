<?php

namespace App\Notifications;

use App\Models\CommandCenter\CommandTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskDueReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected CommandTask $task
    ) {}

    public function via(object $notifiable): array
    {
        // In-app only. Email is intentionally NOT sent per task — a user with
        // many tasks due in one reminder run would otherwise receive one email
        // per task (inbox flood). Email is delivered as a single aggregated
        // digest from ProcessReminders via App\Mail\CommandCenter\TaskDueDigest.
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'task_due_reminder',
            'title'       => "Task due soon: {$this->task->title}",
            'body'        => $this->task->due_date
                ? "Due {$this->task->due_date->diffForHumans()}"
                : 'Due soon',
            'action_url'  => '/corex/command-center/tasks',
            'icon'        => 'clock',
            'task_id'     => $this->task->id,
            'property_id' => $this->task->property_id,
        ];
    }
}
