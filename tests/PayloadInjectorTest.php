<?php

declare(strict_types=1);

use QueueMonitor\PayloadInjector;

it('returns empty array for non-monitored connections', function () {
    $injector = new PayloadInjector(['sqs']);

    expect($injector('redis', 'default', []))->toBe([]);
});

it('injects queue_monitor fields for monitored connections', function () {
    $injector = new PayloadInjector(['sqs']);

    $result = $injector('sqs', 'default', [
        'displayName' => 'MyJob',
        'data' => ['command' => serialize(new stdClass())],
    ]);

    expect($result)->toHaveKey('queue_monitor')
        ->and($result['queue_monitor']['queue_name'])->toBe('default')
        ->and($result['queue_monitor']['display_name'])->toBe('MyJob')
        ->and($result['queue_monitor'])->toHaveKeys(['tracker_id', 'dispatched_at', 'queue_name', 'display_name', 'group_name'])
        ->and($result['queue_monitor']['group_name'])->toBeNull();
});

it('extracts group_name from messageGroup property on the job', function () {
    $job = new stdClass();
    $job->messageGroup = 'openf1-low';

    $injector = new PayloadInjector(['sqs']);

    $result = $injector('sqs', 'default', [
        'displayName' => 'MyJob',
        'data' => ['command' => serialize($job)],
    ]);

    expect($result['queue_monitor']['group_name'])->toBe('openf1-low');
});

it('returns null group_name when the command cannot be deserialized', function () {
    $injector = new PayloadInjector(['sqs']);

    $result = $injector('sqs', 'default', [
        'displayName' => 'MyJob',
        'data' => ['command' => 'not-valid-serialized-data'],
    ]);

    expect($result['queue_monitor']['group_name'])->toBeNull();
});

it('generates a unique tracker_id per invocation', function () {
    $injector = new PayloadInjector(['sqs']);
    $payload = ['displayName' => 'MyJob', 'data' => ['command' => serialize(new stdClass())]];

    $first  = $injector('sqs', 'default', $payload);
    $second = $injector('sqs', 'default', $payload);

    expect($first['queue_monitor']['tracker_id'])->not->toBe($second['queue_monitor']['tracker_id']);
});
