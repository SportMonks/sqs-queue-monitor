<?php

declare(strict_types=1);

namespace QueueMonitor\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class JobDispatched implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $trackerId,
        public readonly string $queueName,
        public readonly ?string $groupName,
        public readonly ?string $displayName,
        public readonly string $dispatchedAt,
        private readonly string $channel,
    ) {}

    /** @return PrivateChannel[] */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'job.dispatched';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'event' => 'job.dispatched',
            'tracker_id' => $this->trackerId,
            'queue_name' => $this->queueName,
            'group_name' => $this->groupName,
            'display_name' => $this->displayName,
            'dispatched_at' => $this->dispatchedAt,
        ];
    }
}
