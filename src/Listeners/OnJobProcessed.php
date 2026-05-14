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
        $startTime  = OnJobProcessing::getStartTime($trackerId);
        $startMemory = OnJobProcessing::getMemoryBytes($trackerId);
        $startCpu   = OnJobProcessing::getCpuUsage($trackerId);
        OnJobProcessing::clearStartTime($trackerId);
        OnJobProcessing::clearMemoryBytes($trackerId);
        OnJobProcessing::clearCpuUsage($trackerId);

        $endTime   = microtime(true);
        $endMemory = memory_get_peak_usage(true);
        $ru        = getrusage();
        $endCpu    = ($ru['ru_utime.tv_sec'] * 1_000_000 + $ru['ru_utime.tv_usec'])
                   + ($ru['ru_stime.tv_sec'] * 1_000_000 + $ru['ru_stime.tv_usec']);
        $wallUs    = $startTime !== null ? ($endTime - $startTime) * 1_000_000 : 0.0;

        event(new JobUpdated(
            trackerId: $trackerId,
            queueName: $monitor['queue_name'],
            groupName: $monitor['group_name'] ?? null,
            displayName: $monitor['display_name'] ?? null,
            dispatchedAt: $monitor['dispatched_at'] ?? null,
            status: 'processed',
            processedAt: now()->toISOString(),
            processingTimeMs: $startTime !== null
                ? (int) round(($endTime - $startTime) * 1000)
                : null,
            memoryBytes: $startMemory !== null
                ? $endMemory - $startMemory
                : null,
            cpuPercent: ($startCpu !== null && $wallUs > 0)
                ? (int) round(($endCpu - $startCpu) / $wallUs * 100)
                : null,
            channel: $this->channel,
        ));
    }
}
