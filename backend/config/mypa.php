<?php

return [
    'app_id_prefix' => env('APP_ID_PREFIX', 'NV'),
    // Prefixes of previously issued App IDs that must keep resolving.
    'app_id_legacy_prefixes' => ['MYPA'],
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
        'max_upload_kb' => (int) env('MYPA_MAX_UPLOAD_KB', 50 * 1024),
        // Per-user storage cap in bytes (plan-driven in Phase 6+).
        'storage_limit_bytes' => (int) env('MYPA_STORAGE_LIMIT_BYTES', 1024 * 1024 * 1024),
        'blocked_extensions' => ['exe', 'bat', 'cmd', 'sh', 'php', 'js', 'msi', 'dll', 'com', 'scr', 'vbs'],
    ],

    'billing' => [
        'tax_label' => env('BILLING_TAX_LABEL', 'GST'),
        // Basis points: 1800 = 18%. Applied on the discounted base (tax-exclusive prices).
        'tax_percent_bp' => (int) env('BILLING_TAX_PERCENT_BP', 1800),
        'order_expiry_minutes' => (int) env('BILLING_ORDER_EXPIRY_MINUTES', 30),
        'seller' => [
            'name' => env('BILLING_SELLER_NAME', 'My PA'),
            'address' => env('BILLING_SELLER_ADDRESS', ''),
            'tax_number' => env('BILLING_SELLER_TAX_NUMBER', ''),
        ],
        'renewal_reminder_days' => [15, 7, 3, 1, 0],
    ],

    'cashfree' => [
        'env' => env('CASHFREE_ENV', 'sandbox'),
        'app_id' => env('CASHFREE_APP_ID'),
        'secret_key' => env('CASHFREE_SECRET_KEY'),
        'api_version' => env('CASHFREE_API_VERSION', '2023-08-01'),
        'base_url' => env('CASHFREE_ENV', 'sandbox') === 'production'
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg',
    ],

    /*
     * Firebase Cloud Messaging, which is how the Android app rings — the
     * shell's WebView has no Push API, so web push cannot reach it. The
     * credentials value is the path to a Google service-account JSON: on the
     * server, out of git, chmod 600, exactly like the LiveKit secret and for
     * the same reason. Absent means FCM is off and every send quietly no-ops.
     */
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@mypa.local'),
    ],

    // Voice assistant AI fallback: interprets phrasings the pattern rules
    // miss. Fully optional — with no key the assistant runs on rules alone.
    'voice' => [
        'ai_key' => env('VOICE_AI_KEY'),
        'ai_model' => env('VOICE_AI_MODEL', 'claude-opus-5'),
    ],

    'webrtc' => [
        'stun_url' => env('STUN_SERVER_URL'),
        'turn_url' => env('TURN_SERVER_URL'),
        'turn_username' => env('TURN_USERNAME'),
        'turn_credential' => env('TURN_CREDENTIAL'),
    ],
];
