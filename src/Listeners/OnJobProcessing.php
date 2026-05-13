<?php

declare(strict_types=1);

namespace QueueMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessing;

class OnJobProcessing
{
    /** @var array<string, float> */
    private static array $startTimes = [];

    public function handle(JobProcessing $event): void
    {
        $monitor = $event->job->payload()['queue_monitor'] ?? null;

        if ($monitor === null) {
            return;
        }

        self::$startTimes[$monitor['tracker_id']] = microtime(true);
    }

    public static function getStartTime(string $trackerId): ?float
    {
        return self::$startTimes[$trackerId] ?? null;
    }

    public static function clearStartTime(string $trackerId): void
    {
        unset(self::$startTimes[$trackerId]);
    }

    public static function recordStartTime(string $trackerId, float $time): void
    {
        self::$startTimes[$trackerId] = $time;
    }
}
