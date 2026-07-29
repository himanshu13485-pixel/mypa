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

    'files' => [
        // Per-file upload cap in kilobytes (admin-configurable via env).
        'max_upload_kb' => (int) env('MYPA_MAX_UPLOAD_KB', 25 * 1024),
        // Per-user storage cap in bytes (plan-driven in Phase 6+).
        'storage_limit_bytes' => (int) env('MYPA_STORAGE_LIMIT_BYTES', 1024 * 1024 * 1024),
        'blocked_extensions' => ['exe', 'bat', 'cmd', 'sh', 'php', 'js', 'msi', 'dll', 'com', 'scr', 'vbs'],
    ],

    'webrtc' => [
        'stun_url' => env('STUN_SERVER_URL'),
        'turn_url' => env('TURN_SERVER_URL'),
        'turn_username' => env('TURN_USERNAME'),
        'turn_credential' => env('TURN_CREDENTIAL'),
    ],
];
