<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessing;
use QueueMonitor\Listeners\OnJobProcessing;

function makeProcessingJob(array $payload): object
{
    return new class($payload) {
        public function __construct(private array $data) {}

        public function payload(): array
        {
            return $this->data;
        }
    };
}

it('records start time for monitored jobs', function () {
    OnJobProcessing::clearStartTime('abc-123');

    $job = makeProcessingJob([
        'queue_monitor' => ['tracker_id' => 'abc-123'],
    ]);

    (new OnJobProcessing())->handle(new JobProcessing('sqs', $job));

    expect(OnJobProcessing::getStartTime('abc-123'))->toBeFloat();
});

it('does nothing when queue_monitor key is absent', function () {
    OnJobProcessing::clearStartTime('no-key');

    $job = makeProcessingJob([]);

    (new OnJobProcessing())->handle(new JobProcessing('sqs', $job));

    expect(OnJobProcessing::getStartTime('no-key'))->toBeNull();
});

it('clearStartTime removes the entry', function () {
    OnJobProcessing::recordStartTime('abc-123', microtime(true));
    OnJobProcessing::clearStartTime('abc-123');

    expect(OnJobProcessing::getStartTime('abc-123'))->toBeNull();
});
