<?php
/**
 * Local stand-in for POST /api/integrations/health.
 * Records each request and answers 200, or 429 when X-Location-Id is "limited".
 */

$log = getenv('KIYOH_HEALTH_LOG');
if (!is_string($log) || $log === '') {
    $log = sys_get_temp_dir() . '/kiyoh-health-test.log';
}

$raw = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}

file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => is_string($raw) ? $raw : '',
]) . "\n", FILE_APPEND);

$location = '';
foreach ($headers as $name => $value) {
    if (strtolower((string) $name) === 'x-location-id') {
        $location = (string) $value;
    }
}

header('Content-Type: application/json');
if ($location === 'limited') {
    header('Retry-After: 12');
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited', 'retryAfterSeconds' => 12]);
    return;
}

http_response_code(200);
echo json_encode(['integration' => ['heartbeat' => 'current']]);
