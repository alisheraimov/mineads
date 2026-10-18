<?php

return [
    'heartbeat_timeout_seconds' => 300,
    'setup_token_hours' => 24,
    'latest_plugin_version' => null,
    'minimum_payout_micros' => (int) env('PUBLISHER_MINIMUM_PAYOUT_MICROS', 50000000),
    'payout_hold_days_min' => 3,
    'payout_hold_days_max' => 4,
];
