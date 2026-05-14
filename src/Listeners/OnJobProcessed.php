<?php

declare(strict_types=1);

namespace QueueMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use QueueMonitor\Events\JobUpdated;

class OnJobProcessed
{
    public function __construct(private readonly string $channel) {}

    public function handle(JobProcessed $event): void
    {
        $monitor = $event->job->payload()['queue_monitor'] ?? null;

        if ($monitor === null) {
            return;
        }

        $trackerId = $monitor['tracker_id'];
        $startTime = OnJobProcessing::getStartTime($trackerId);
        $startMemory = OnJobProcessing::getMemoryBytes($trackerId);
        OnJobProcessing::clearStartTime($trackerId);
        OnJobProcessing::clearMemoryBytes($trackerId);

        event(new JobUpdated(
            trackerId: $trackerId,
            queueName: $monitor['queue_name'],
            groupName: $monitor['group_name'] ?? null,
            displayName: $monitor['display_name'] ?? null,
            dispatchedAt: $monitor['dispatched_at'] ?? null,
            status: 'processed',
            processedAt: now()->toISOString(),
            processingTimeMs: $startTime !== null
                ? (int) round((microtime(true) - $startTime) * 1000)
                : null,
            memoryBytes: $startMemory !== null
                ? memory_get_usage(true) - $startMemory
                : null,
            channel: $this->channel,
        ));
    }
}
