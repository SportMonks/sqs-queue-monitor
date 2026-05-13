<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use QueueMonitor\Events\JobDispatched;
use QueueMonitor\Listeners\OnJobQueued;

it('dispatches JobDispatched when queue_monitor key is present', function () {
    Event::fake([JobDispatched::class]);

    $payload = json_encode([
        'queue_monitor' => [
            'tracker_id'    => 'abc-123',
            'dispatched_at' => '2026-05-13T10:00:00.000Z',
            'queue_name'    => 'default',
            'group_name'    => 'openf1',
            'display_name'  => 'MyJob',
        ],
    ]);

    (new OnJobQueued('queue-monitor'))->handle(
        new JobQueued('sqs', 'default', 'msg-id', new stdClass(), $payload, null)
    );

    Event::assertDispatched(JobDispatched::class, fn (JobDispatched $e) =>
        $e->trackerId === 'abc-123' &&
        $e->queueName === 'default' &&
        $e->groupName === 'openf1' &&
        $e->displayName === 'MyJob' &&
        $e->dispatchedAt === '2026-05-13T10:00:00.000Z'
    );
});

it('does nothing when queue_monitor key is absent', function () {
    Event::fake([JobDispatched::class]);

    (new OnJobQueued('queue-monitor'))->handle(
        new JobQueued('sqs', 'default', 'msg-id', new stdClass(), json_encode(['displayName' => 'MyJob']), null)
    );

    Event::assertNotDispatched(JobDispatched::class);
});

it('does nothing when payload is not valid JSON', function () {
    Event::fake([JobDispatched::class]);

    (new OnJobQueued('queue-monitor'))->handle(
        new JobQueued('sqs', 'default', 'msg-id', new stdClass(), 'not-json', null)
    );

    Event::assertNotDispatched(JobDispatched::class);
});
