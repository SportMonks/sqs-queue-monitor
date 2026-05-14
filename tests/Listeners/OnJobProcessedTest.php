<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use QueueMonitor\Events\JobUpdated;
use QueueMonitor\Listeners\OnJobProcessed;
use QueueMonitor\Listeners\OnJobProcessing;
use QueueMonitor\Tests\Support\FakeJob;

function makeProcessedJob(array $payload): FakeJob
{
    return new FakeJob($payload);
}

it('dispatches JobUpdated with processed status and processing time', function () {
    Event::fake([JobUpdated::class]);

    OnJobProcessing::recordStartTime('abc-123', microtime(true) - 0.5);
    OnJobProcessing::recordMemoryBytes('abc-123', memory_get_usage(true));

    $job = makeProcessedJob([
        'queue_monitor' => [
            'tracker_id'    => 'abc-123',
            'queue_name'    => 'default',
            'group_name'    => 'openf1',
            'display_name'  => 'MyJob',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
        ],
    ]);

    (new OnJobProcessed('queue-monitor'))->handle(new JobProcessed('sqs', $job));

    Event::assertDispatched(JobUpdated::class, fn (JobUpdated $e) =>
        $e->trackerId === 'abc-123' &&
        $e->status === 'processed' &&
        $e->queueName === 'default' &&
        $e->groupName === 'openf1' &&
        $e->displayName === 'MyJob' &&
        $e->dispatchedAt === '2026-05-13T10:00:00.000Z' &&
        $e->processingTimeMs >= 400 && $e->processingTimeMs <= 600 &&
        is_int($e->memoryBytes)
    );
});

it('dispatches JobUpdated with null processing time when start was not recorded', function () {
    Event::fake([JobUpdated::class]);

    OnJobProcessing::clearStartTime('no-start');
    OnJobProcessing::clearMemoryBytes('no-start');

    $job = makeProcessedJob([
        'queue_monitor' => [
            'tracker_id'    => 'no-start',
            'queue_name'    => 'default',
            'group_name'    => null,
            'display_name'  => 'MyJob',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
        ],
    ]);

    (new OnJobProcessed('queue-monitor'))->handle(new JobProcessed('sqs', $job));

    Event::assertDispatched(JobUpdated::class, fn (JobUpdated $e) =>
        $e->processingTimeMs === null &&
        $e->memoryBytes === null
    );
});

it('clears start time after dispatch', function () {
    Event::fake([JobUpdated::class]);

    OnJobProcessing::recordStartTime('cleanup-test', microtime(true));

    $job = makeProcessedJob([
        'queue_monitor' => [
            'tracker_id'    => 'cleanup-test',
            'queue_name'    => 'default',
            'group_name'    => null,
            'display_name'  => 'MyJob',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
        ],
    ]);

    (new OnJobProcessed('queue-monitor'))->handle(new JobProcessed('sqs', $job));

    expect(OnJobProcessing::getStartTime('cleanup-test'))->toBeNull();
});

it('does nothing when queue_monitor key is absent', function () {
    Event::fake([JobUpdated::class]);

    $job = makeProcessedJob([]);

    (new OnJobProcessed('queue-monitor'))->handle(new JobProcessed('sqs', $job));

    Event::assertNotDispatched(JobUpdated::class);
});
