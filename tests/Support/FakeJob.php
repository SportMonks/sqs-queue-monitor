<?php

declare(strict_types=1);

namespace QueueMonitor\Tests\Support;

use Illuminate\Contracts\Queue\Job;

class FakeJob implements Job
{
    public function __construct(private readonly array $data) {}

    public function payload(): array { return $this->data; }

    public function uuid(): ?string { return null; }

    public function getJobId(): string { return ''; }

    public function fire(): void {}

    public function release($delay = 0): void {}

    public function isReleased(): bool { return false; }

    public function delete(): void {}

    public function isDeleted(): bool { return false; }

    public function isDeletedOrReleased(): bool { return false; }

    public function attempts(): int { return 0; }

    public function hasFailed(): bool { return false; }

    public function markAsFailed(): void {}

    public function fail($e = null): void {}

    public function maxTries(): ?int { return null; }

    public function maxExceptions(): ?int { return null; }

    public function timeout(): ?int { return null; }

    public function retryUntil(): ?int { return null; }

    public function getName(): string { return ''; }

    public function resolveName(): string { return ''; }

    public function resolveQueuedJobClass(): string { return ''; }

    public function getConnectionName(): string { return ''; }

    public function getQueue(): string { return ''; }

    public function getRawBody(): string { return ''; }
}
