<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use QueueMonitor\Http\QueueMonitorChannel;

it('returns false when the queue-monitor gate is not defined', function () {
    expect((new QueueMonitorChannel())->join(new stdClass()))->toBeFalse();
});

it('returns true when the gate is defined and allows access', function () {
    Gate::define('queue-monitor', fn ($user) => true);

    expect((new QueueMonitorChannel())->join(new stdClass()))->toBeTrue();
});

it('returns false when the gate is defined and denies access', function () {
    Gate::define('queue-monitor', fn ($user) => false);

    expect((new QueueMonitorChannel())->join(new stdClass()))->toBeFalse();
});

it('returns false for null user regardless of gate', function () {
    Gate::define('queue-monitor', fn ($user) => true);

    expect((new QueueMonitorChannel())->join(null))->toBeFalse();
});
