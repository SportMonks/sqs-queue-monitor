<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::get(config('queue-monitor.dashboard.path', 'queue-monitor'), function () {
        return view('queue-monitor::dashboard', [
            'connections' => config('queue-monitor.dashboard.connections', []),
        ]);
    })->name('queue-monitor.dashboard');

    // Signs channel subscriptions for any configured connection without requiring
    // an authenticated session. Uses the matching Reverb app secret for the key
    // passed as a query param, so multi-app dashboards sign correctly.
    Route::post(config('queue-monitor.dashboard.path', 'queue-monitor') . '/auth', function (Request $request) {
        $key = $request->query('key');

        $app = collect(config('reverb.apps.apps', []))
            ->first(fn (array $app): bool => $app['key'] === $key);

        if (! $app) {
            return Broadcast::driver()->validAuthenticationResponse($request, true);
        }

        $signature = hash_hmac('sha256', $request->socket_id.':'.$request->channel_name, $app['secret']);

        return ['auth' => $app['key'].':'.$signature];
    })->name('queue-monitor.auth');
});
