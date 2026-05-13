<?php

declare(strict_types=1);

namespace QueueMonitor\Http;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class QueueMonitorChannel
{
    public function join(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! Gate::has('queue-monitor')) {
            Log::warning('Queue Monitor: no "queue-monitor" gate defined. Define Gate::define("queue-monitor", ...) in your AppServiceProvider to authorize channel access.');

            return false;
        }

        return Gate::forUser($user)->allows('queue-monitor');
    }
}
