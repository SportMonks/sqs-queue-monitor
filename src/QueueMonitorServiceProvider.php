<?php

declare(strict_types=1);

namespace QueueMonitor;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

        Queue::createPayloadUsing(new PayloadInjector($connections));

        Event::listen(JobQueued::class, new OnJobQueued($channel));
        Event::listen(JobProcessing::class, new OnJobProcessing);
        Event::listen(JobProcessed::class, new OnJobProcessed($channel));
        Event::listen(JobFailed::class, new OnJobFailed($channel));

        Broadcast::channel($channel, QueueMonitorChannel::class);

        if ((bool) config('queue-monitor.dashboard.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'queue-monitor');
        }
    }
}
