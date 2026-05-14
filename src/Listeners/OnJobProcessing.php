<?php

declare(strict_types=1);

namespace QueueMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessing;

class OnJobProcessing
{
    /** @var array<string, float> */
    private static array $startTimes = [];

    /** @var array<string, int> */
    private static array $memoryBytes = [];

    public function handle(JobProcessing $event): void
    {
        $monitor = $event->job->payload()['queue_monitor'] ?? null;

        if ($monitor === null) {
            return;
        }

        $trackerId = $monitor['tracker_id'];
        self::$startTimes[$trackerId] = microtime(true);
        self::$memoryBytes[$trackerId] = memory_get_usage(true);
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

    public static function getMemoryBytes(string $trackerId): ?int
    {
        return self::$memoryBytes[$trackerId] ?? null;
    }

    public static function clearMemoryBytes(string $trackerId): void
    {
        unset(self::$memoryBytes[$trackerId]);
    }

    public static function recordMemoryBytes(string $trackerId, int $bytes): void
    {
        self::$memoryBytes[$trackerId] = $bytes;
    }
}
