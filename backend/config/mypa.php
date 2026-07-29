<?php

return [
    'app_id_prefix' => env('APP_ID_PREFIX', 'MYPA'),
    'app_id_start' => (int) env('APP_ID_START', 100001),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'task_statuses' => [
        'draft', 'not_started', 'planned', 'in_progress', 'waiting',
        'on_hold', 'completed', 'cancelled', 'overdue', 'archived',
    ],

    'task_priorities' => [
        'low', 'normal', 'medium', 'high', 'urgent', 'critical',
    ],

    'webrtc' => [
        'stun_url' => env('STUN_SERVER_URL'),
        'turn_url' => env('TURN_SERVER_URL'),
        'turn_username' => env('TURN_USERNAME'),
        'turn_credential' => env('TURN_CREDENTIAL'),
    ],
];
