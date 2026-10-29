<?php

return [
    // HTTP-backed Ping operations are available when the code and schema are installed.
    // Recipient privacy, trusted devices and block checks remain mandatory.
    'enabled' => (bool) env('ORBIT_PING_ENABLED', true),
    'custom_max_characters' => 160,
    'sender_per_minute' => (int) env('ORBIT_PING_SENDER_PER_MINUTE', 12),
    'pair_per_hour' => (int) env('ORBIT_PING_PAIR_PER_HOUR', 6),
    'page_size_max' => 30,
    // Push and WebSocket transports are not asserted by the HTTP implementation.
    'push_enabled' => false,
    'realtime_enabled' => false,
];
