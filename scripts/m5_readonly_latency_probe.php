<?php

declare(strict_types=1);

/**
 * Orbit M5 read-only Circle list latency probe.
 *
 * Local development is the default. Remote benchmarking must be expressly
 * authorized using ORBIT_M5_REMOTE_PROBE_APPROVED=YES. Never point this at
 * production without an approved load-test window.
 *
 * Example:
 * ORBIT_M5_PROBE_TOKEN=... php scripts/m5_readonly_latency_probe.php
 *
 * Exits non-zero for any non-200 response, transport failure or p95 > 250ms.
 * No mutations, token output, request bodies or hidden retries.
 */
$base = rtrim((string) (getenv('ORBIT_M5_PROBE_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$token = getenv('ORBIT_M5_PROBE_TOKEN');
$parsed = parse_url($base);
$scheme = strtolower((string) ($parsed['scheme'] ?? ''));
$host = strtolower((string) ($parsed['host'] ?? ''));
$loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

if (!in_array($scheme, ['http', 'https'], true) || $host === ''
    || isset($parsed['user'], $parsed['pass'])
    || (!$loopback && ($scheme !== 'https'
        || getenv('ORBIT_M5_REMOTE_PROBE_APPROVED') !== 'YES'))) {
    fwrite(STDERR, "Only local development is allowed by default. Remote probes need explicit approval and HTTPS.\n");
    exit(2);
}
if (!is_string($token) || !preg_match('/^[A-Za-z0-9._-]{20,3500}$/D', $token)) {
    fwrite(STDERR, "ORBIT_M5_PROBE_TOKEN is required (use a disposable QA account).\n");
    exit(2);
}
$requested = (int) (getenv('ORBIT_M5_PROBE_SAMPLES') ?: 30);
if ($requested < 10 || $requested > 200) {
    fwrite(STDERR, "ORBIT_M5_PROBE_SAMPLES must be 10 through 200.\n");
    exit(2);
}

$url = $base . '/api/v1/circles';
$latency = [];
for ($n = 1; $n <= $requested; $n++) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\nCache-Control: no-store\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $start = hrtime(true);
    $content = @file_get_contents($url, false, $context);
    $duration = (hrtime(true) - $start) / 1000000;
    $headers = $http_response_header ?? [];
    $status = (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $match))
        ? (int) $match[1] : 0;
    if ($content === false || $status !== 200) {
        fwrite(STDERR, "Read-only request {$n} failed with HTTP {$status}. No latency pass can be claimed.\n");
        exit(1);
    }
    $latency[] = $duration;
}
sort($latency, SORT_NUMERIC);
$index = (int) ceil(0.95 * count($latency)) - 1;
$p95 = $latency[$index];
$median = $latency[(int) floor((count($latency) - 1) / 2)];
printf("M5 read-only GET /api/v1/circles: %d samples; median %.1f ms; p95 %.1f ms (target <250 ms).\n",
    count($latency), $median, $p95);
fwrite(STDOUT, "Single-client probe only; this is not concurrency, real-device, or production sign-off.\n");
exit($p95 < 250.0 ? 0 : 1);
