<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::get(config('queue-monitor.dashboard.path', 'queue-monitor'), function () {
        return view('queue-monitor::dashboard', [
            'channel'     => config('queue-monitor.channel'),
            'connections' => config('queue-monitor.dashboard.connections', []),
        ]);
    })->name('queue-monitor.dashboard');

    // Custom auth endpoint that signs channel subscriptions without requiring an
    // authenticated session. The dashboard is a local dev tool; it has no login.
    Route::post(config('queue-monitor.dashboard.path', 'queue-monitor') . '/auth', function (Request $request) {
        return Broadcast::driver()->validAuthenticationResponse($request, true);
    })->name('queue-monitor.auth');
});
