<?php

return [
    'key' => env('PAY_API_KEY', ''),
    'url' => env('PAY_URL', 'https://pay.noria.co.ke'),
    'timeout' => (int) env('PAY_TIMEOUT', 30),
    'retries' => (int) env('PAY_RETRIES', 2),
    'webhook_secret' => env('PAY_WEBHOOK_SECRET'),
    'webhook_tolerance' => (int) env('PAY_WEBHOOK_TOLERANCE', 300),
];
