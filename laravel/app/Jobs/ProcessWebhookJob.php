<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookEvent $event) {}

    public function handle(): void
    {
        $this->event->update(['status' => 'processing']);

        // TODO: What happens if two jobs process the same event simultaneously?
        // Simulate processing work
        sleep(1);

        $this->event->update(['status' => 'completed']);
    }

    public function failed(\Throwable $e): void
    {
        $this->event->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
