<?php

declare(strict_types=1);

return [
    'channel' => env('QUEUE_MONITOR_CHANNEL', 'queue-monitor'),

    'connections' => ['sqs'],

    'dashboard' => [
        'enabled' => env('QUEUE_MONITOR_DASHBOARD', false),
        'path'    => 'queue-monitor',
        'connections' => [
            [
                'name'         => env('APP_NAME', 'App'),
                'channel'      => env('QUEUE_MONITOR_CHANNEL', 'queue-monitor'),
                'key'          => env('REVERB_APP_KEY', ''),
                'host'         => env('REVERB_HOST', 'localhost'),
                'port'         => (int) env('REVERB_PORT', 8080),
                'authEndpoint' => env('APP_URL', 'http://localhost') . '/queue-monitor/auth',
            ],
        ],
    ],
];
