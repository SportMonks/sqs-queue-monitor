<?php

declare(strict_types=1);

namespace QueueMonitor\Listeners;

use Illuminate\Queue\Events\JobQueued;
use QueueMonitor\Events\JobDispatched;

class OnJobQueued
{
    public function __construct(private readonly string $channel) {}

    public function handle(JobQueued $event): void
    {
        $decoded = json_decode($event->payload, associative: true);

        if (! is_array($decoded)) {
            return;
        }

        $monitor = $decoded['queue_monitor'] ?? null;

        if ($monitor === null) {
            return;
        }

        event(new JobDispatched(
            trackerId: $monitor['tracker_id'],
            queueName: $monitor['queue_name'],
            groupName: $monitor['group_name'] ?? null,
            displayName: $monitor['display_name'] ?? null,
            dispatchedAt: $monitor['dispatched_at'],
            channel: $this->channel,
        ));
    }
}
