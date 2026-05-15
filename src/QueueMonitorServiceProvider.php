<?php

declare(strict_types=1);

namespace QueueMonitor;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use QueueMonitor\Http\QueueMonitorChannel;
use QueueMonitor\Listeners\OnJobFailed;
use QueueMonitor\Listeners\OnJobProcessed;
use QueueMonitor\Listeners\OnJobProcessing;
use QueueMonitor\Listeners\OnJobQueued;

class QueueMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-monitor.php', 'queue-monitor');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/queue-monitor.php' => config_path('queue-monitor.php'),
            ], 'queue-monitor-config');
        }

        $channel = (string) config('queue-monitor.channel');
        $connections = (array) config('queue-monitor.connections');

        BaseQueue::createPayloadUsing(new PayloadInjector($connections));

        Event::listen(JobQueued::class, [new OnJobQueued($channel), 'handle']);
        Event::listen(JobProcessing::class, [new OnJobProcessing, 'handle']);
        Event::listen(JobProcessed::class, [new OnJobProcessed($channel), 'handle']);
        Event::listen(JobFailed::class, [new OnJobFailed($channel), 'handle']);

        Broadcast::channel($channel, QueueMonitorChannel::class);

        if ((bool) config('queue-monitor.dashboard.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'queue-monitor');
        }
    }
}
