<?php

declare(strict_types=1);

namespace QueueMonitor\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class JobUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $trackerId,
        public readonly string $queueName,
        public readonly ?string $groupName,
        public readonly ?string $displayName,
        public readonly ?string $dispatchedAt,
        public readonly string $status,
        public readonly string $processedAt,
        public readonly ?int $processingTimeMs,
        public readonly ?int $memoryBytes,
        private readonly string $channel,
    ) {}

    /** @return PrivateChannel[] */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'job.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'event' => 'job.updated',
            'tracker_id' => $this->trackerId,
            'queue_name' => $this->queueName,
            'group_name' => $this->groupName,
            'display_name' => $this->displayName,
            'dispatched_at' => $this->dispatchedAt,
            'status' => $this->status,
            'processed_at' => $this->processedAt,
            'processing_time_ms' => $this->processingTimeMs,
            'memory_bytes' => $this->memoryBytes,
        ];
    }
}
