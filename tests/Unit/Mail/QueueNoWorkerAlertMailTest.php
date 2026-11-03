<?php

namespace Tests\Unit\Mail;

use App\Mail\QueueNoWorkerAlertMail;
use Tests\TestCase;

/**
 * The queue-coverage watchdog sends this mail when a queue has jobs and no worker. It declared `public string $queue`
 * while its Queueable trait declares `public $queue` - a fatal error the moment the class loads (so the alert could never
 * be sent, and a full test run died at the first test that loaded every class). The queue name is `queueName` now.
 */
class QueueNoWorkerAlertMailTest extends TestCase
{
    public function test_the_alert_loads_and_names_the_queue_with_the_count_in_its_subject_and_body(): void
    {
        $mail = new QueueNoWorkerAlertMail('p24images', 3, 125, 'qa1-host', '2026-11-02 17:00:00');

        $this->assertSame('p24images', $mail->queueName);
        $this->assertNull($mail->queue, 'the mailable\'s own queue setting (Queueable) is untouched');
        $this->assertStringContainsString('No worker for queue p24images', $mail->envelope()->subject);
        $this->assertStringContainsString('3 job(s) waiting', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('p24images', $html);
        $this->assertStringContainsString('qa1-host', $html);
    }
}
