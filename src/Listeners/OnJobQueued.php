<?php

declare(strict_types=1);

namespace QueueMonitor\Listeners;

use DateTimeInterface;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
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
            delayedUntil: $this->resolveDelayedUntil($event->delay, $monitor['dispatched_at']),
            channel: $this->channel,
        ));
    }

    private function resolveDelayedUntil(mixed $delay, string $dispatchedAt): ?string
    {
        if ($delay instanceof DateTimeInterface) {
            return Carbon::instance($delay)->toISOString();
        }

        if (is_int($delay) && $delay > 0) {
            return Carbon::parse($dispatchedAt)->addSeconds($delay)->toISOString();
        }

        return null;
    }
}
