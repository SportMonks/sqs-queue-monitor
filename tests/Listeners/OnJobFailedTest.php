<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use QueueMonitor\Events\JobUpdated;
use QueueMonitor\Listeners\OnJobFailed;
use QueueMonitor\Listeners\OnJobProcessing;

function makeFailedJob(array $payload): object
{
    return new class($payload) {
        public function __construct(private array $data) {}

        public function payload(): array
        {
            return $this->data;
        }
    };
}

it('dispatches JobUpdated with failed status', function () {
    Event::fake([JobUpdated::class]);

    OnJobProcessing::recordStartTime('fail-123', microtime(true) - 0.3);

    $job = makeFailedJob([
        'queue_monitor' => [
            'tracker_id'    => 'fail-123',
            'queue_name'    => 'default',
            'group_name'    => 'openf1',
            'display_name'  => 'MyJob',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
        ],
    ]);

    (new OnJobFailed('queue-monitor'))->handle(new JobFailed('sqs', $job, new RuntimeException('boom')));

    Event::assertDispatched(JobUpdated::class, fn (JobUpdated $e) =>
        $e->trackerId === 'fail-123' &&
        $e->status === 'failed' &&
        $e->processingTimeMs >= 200 && $e->processingTimeMs <= 400
    );
});

it('clears start time after dispatch', function () {
    Event::fake([JobUpdated::class]);

    OnJobProcessing::recordStartTime('fail-cleanup', microtime(true));

    $job = makeFailedJob([
        'queue_monitor' => [
            'tracker_id'    => 'fail-cleanup',
            'queue_name'    => 'default',
            'group_name'    => null,
            'display_name'  => 'MyJob',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
        ],
    ]);

    (new OnJobFailed('queue-monitor'))->handle(new JobFailed('sqs', $job, new RuntimeException('boom')));

    expect(OnJobProcessing::getStartTime('fail-cleanup'))->toBeNull();
});

it('does nothing when queue_monitor key is absent', function () {
    Event::fake([JobUpdated::class]);

    $job = makeFailedJob([]);

    (new OnJobFailed('queue-monitor'))->handle(new JobFailed('sqs', $job, new RuntimeException('boom')));

    Event::assertNotDispatched(JobUpdated::class);
});
