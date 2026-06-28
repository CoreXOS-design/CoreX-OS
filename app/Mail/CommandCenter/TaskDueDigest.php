<?php

namespace App\Mail\CommandCenter;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class TaskDueDigest extends Mailable
{
    use Queueable, SerializesModels;

    public string $greeting;
    public string $dateLine;
    public int $taskCount;

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\CommandCenter\CommandTask> $tasks
     */
    public function __construct(
        public User $user,
        public Collection $tasks,
    ) {
        $this->greeting  = $user->first_name ?? $user->name ?? 'there';
        $this->dateLine  = now()->format('l, d F Y');
        $this->taskCount = $tasks->count();
    }

    public function envelope(): Envelope
    {
        $noun = $this->taskCount === 1 ? 'task' : 'tasks';

        return new Envelope(
            subject: "You have {$this->taskCount} {$noun} due",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.command-center.task-due-digest',
        );
    }
}
