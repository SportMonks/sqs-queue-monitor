<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessing;
use QueueMonitor\Listeners\OnJobProcessing;
use QueueMonitor\Tests\Support\FakeJob;

function makeProcessingJob(array $payload): FakeJob
{
    return new FakeJob($payload);
}

it('records start time for monitored jobs', function () {
    OnJobProcessing::clearStartTime('abc-123');

    $job = makeProcessingJob([
        'queue_monitor' => ['tracker_id' => 'abc-123'],
    ]);

    (new OnJobProcessing())->handle(new JobProcessing('sqs', $job));

    expect(OnJobProcessing::getStartTime('abc-123'))->toBeFloat();
});

it('records memory bytes for monitored jobs', function () {
    OnJobProcessing::clearMemoryBytes('abc-123');

    $job = makeProcessingJob([
        'queue_monitor' => ['tracker_id' => 'abc-123'],
    ]);

    (new OnJobProcessing())->handle(new JobProcessing('sqs', $job));

    expect(OnJobProcessing::getMemoryBytes('abc-123'))->toBeInt();
});

it('does nothing when queue_monitor key is absent', function () {
    OnJobProcessing::clearStartTime('no-key');
    OnJobProcessing::clearMemoryBytes('no-key');

    $job = makeProcessingJob([]);

    (new OnJobProcessing())->handle(new JobProcessing('sqs', $job));

    expect(OnJobProcessing::getStartTime('no-key'))->toBeNull();
    expect(OnJobProcessing::getMemoryBytes('no-key'))->toBeNull();
});

it('clearStartTime removes the entry', function () {
    OnJobProcessing::recordStartTime('abc-123', microtime(true));
    OnJobProcessing::clearStartTime('abc-123');

    expect(OnJobProcessing::getStartTime('abc-123'))->toBeNull();
});

it('clearMemoryBytes removes the entry', function () {
    OnJobProcessing::recordMemoryBytes('abc-123', 1024);
    OnJobProcessing::clearMemoryBytes('abc-123');

    expect(OnJobProcessing::getMemoryBytes('abc-123'))->toBeNull();
});
