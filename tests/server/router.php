<?php

declare(strict_types=1);

// Mock TeePay API for the test suite, run with `php -S 127.0.0.1:<port> router.php`.
// Replays tests/fixtures/fixtures.json and records every request. Tests steer it through
// /__control endpoints: reset, enqueue one-shot responses, and read the recorded requests.

$state = getenv('TEEPAY_MOCK_STATE') ?: sys_get_temp_dir() . '/teepay-php-mock';
if (!is_dir($state)) {
    mkdir($state, 0777, true);
}
$queueFile = $state . '/queue.json';
$logFile = $state . '/requests.jsonl';
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/fixtures.json'), true);

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$rawQuery = parse_url($uri, PHP_URL_QUERY) ?? '';
$body = (string) file_get_contents('php://input');

function send(int $status, mixed $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
}

if (str_starts_with($path, '/__control/')) {
    switch (substr($path, strlen('/__control/'))) {
        case 'reset':
            @unlink($queueFile);
            @unlink($logFile);
            send(200, ['ok' => true]);
            return true;
        case 'enqueue':
            $queue = is_file($queueFile) ? json_decode((string) file_get_contents($queueFile), true) : [];
            foreach (json_decode($body, true) as $item) {
                $queue[] = $item;
            }
            file_put_contents($queueFile, json_encode($queue));
            send(200, ['ok' => true]);
            return true;
        case 'requests':
            $lines = is_file($logFile) ? array_filter(explode("\n", (string) file_get_contents($logFile))) : [];
            send(200, '[' . implode(',', $lines) . ']');
            return true;
    }
    send(404, ['error' => 'unknown control']);
    return true;
}

parse_str($rawQuery, $query);
file_put_contents($logFile, json_encode([
    'method' => $method,
    'path' => $path,
    'raw_path' => explode('?', $uri, 2)[0],
    'query' => $query,
    'raw_query' => $rawQuery,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => $body,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$requestId = bin2hex(random_bytes(8));
$headers = [
    'X-Request-ID' => $requestId,
    'TeePay-Version' => '2026-10-18',
    'Cache-Control' => 'no-store',
    'RateLimit-Limit' => '120',
    'RateLimit-Remaining' => '119',
    'RateLimit-Reset' => '60',
];

// One-shot override from the queue, if any.
$queue = is_file($queueFile) ? json_decode((string) file_get_contents($queueFile), true) : [];
if ($queue) {
    $next = array_shift($queue);
    file_put_contents($queueFile, json_encode($queue));
    if (isset($next['delay_ms'])) {
        usleep((int) $next['delay_ms'] * 1000);
    }
    if (isset($next['error'])) {
        $error = $fixtures['errors'][$next['error']];
        send($error['status'], $error['body'], array_merge($headers, $error['headers'] ?? []));
        return true;
    }
    if (isset($next['status'])) {
        send((int) $next['status'], $next['raw'] ?? ($next['body'] ?? new stdClass()), array_merge($headers, $next['headers'] ?? []));
        return true;
    }
}

foreach ($fixtures['operations'] as $operation) {
    $pattern = '#^' . preg_replace('#\\\\\{[a-z_]+\\\\\}#', '[^/]+', preg_quote($operation['path'], '#')) . '$#';
    if ($operation['method'] === $method && preg_match($pattern, $path)) {
        send($operation['status'], $operation['body'], $headers);
        return true;
    }
}

$error = $fixtures['errors']['not_found'];
send(404, $error['body'], $headers);
return true;
