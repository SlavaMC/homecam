<?php

$data = rtrim(env('HOMECAM_DATA', storage_path('camdata')), '/');

return [
    'data_dir' => $data,
    'rec_dir' => $data . '/rec',
    'exports_dir' => $data . '/exports',

    // Rotation: total size of recordings is kept below this limit (GB, 1 GB = 10^9 bytes).
    'storage_limit_gb' => (float) env('STORAGE_LIMIT_GB', 100),
    // Always keep at least this much free space on the disk (GB).
    'min_free_gb' => (float) env('MIN_FREE_GB', 5),
    // Camera is reported offline after this many seconds without new video.
    'offline_after' => (int) env('CAMERA_OFFLINE_AFTER', 60),
    // Exported files are deleted after this many hours.
    'export_ttl_hours' => (int) env('EXPORT_TTL_HOURS', 48),

    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'telegram_chat_id' => env('TELEGRAM_CHAT_ID'),
    // e.g. http://user:pass@host:3128 or socks5h://user:pass@host:1080 (empty = direct)
    'telegram_proxy' => env('TELEGRAM_PROXY'),

    'go2rtc_url' => env('GO2RTC_URL', 'http://go2rtc:1984'),

    'admin_login' => env('ADMIN_LOGIN', 'admin'),
    'admin_password' => env('ADMIN_PASSWORD'),
];
