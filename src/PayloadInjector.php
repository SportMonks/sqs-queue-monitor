<?php

declare(strict_types=1);

namespace QueueMonitor;

use Illuminate\Support\Str;

class PayloadInjector
{
    /** @param string[] $monitoredConnections */
    public function __construct(private readonly array $monitoredConnections) {}

    /** @param array<string, mixed> $payload */
    public function __invoke(string $connectionName, string $queue, array $payload): array
    {
        if (! in_array($connectionName, $this->monitoredConnections, true)) {
            return [];
        }

        return [
            'queue_monitor' => [
                'tracker_id' => (string) Str::uuid(),
                'dispatched_at' => now()->toISOString(),
                'queue_name' => $queue,
                'group_name' => $this->extractGroupName($payload),
                'display_name' => $payload['displayName'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function extractGroupName(array $payload): ?string
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_object($command)) {
            return null;
        }

        return property_exists($command, 'messageGroup') ? $command->messageGroup : null;
    }
}
