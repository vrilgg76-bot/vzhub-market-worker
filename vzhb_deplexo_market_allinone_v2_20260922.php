<?php
declare(strict_types=1);

/*
 * VZHB Deplexo Persistent Worker V3
 * - Internal VRILZHUB market only; no BTC/Binance/external price source.
 * - Keeps moving approximately every second even when no browser is open.
 * - HTTP API + admin price adjustment in the same long-running PHP process.
 * - Designed for Deplexo PHP CLI with PORT supplied by the platform.
 */

$port = (int)($_ENV['PORT'] ?? getenv('PORT') ?: 3000);
$adminKey = (string)($_ENV['ADMIN_KEY'] ?? getenv('ADMIN_KEY') ?: '');
$startPrice = (float)($_ENV['START_PRICE'] ?? getenv('START_PRICE') ?: 1000);
$maxTickPercent = (float)($_ENV['MAX_TICK_PERCENT'] ?? getenv('MAX_TICK_PERCENT') ?: 0.25);
$minPrice = (float)($_ENV['MIN_PRICE'] ?? getenv('MIN_PRICE') ?: 1);
$maxPrice = (float)($_ENV['MAX_PRICE'] ?? getenv('MAX_PRICE') ?: 1000000000);

if ($port < 1 || $port > 65535) $port = 3000;
if ($startPrice < $minPrice) $startPrice = $minPrice;
if ($maxTickPercent <= 0) $maxTickPercent = 0.25;

$price = $startPrice;
$previousPrice = $price;
$updatedAt = microtime(true);
$history = [];
$historyLimit = 600;
$lastTick = microtime(true);

// Temporary admin price-control state. When inactive, the original random tick runs unchanged.
$priceControl = [
    'active' => false,
    'started_at' => 0.0,
    'duration' => 0,
    'direction' => 'down',
    'mode' => 'percent',
    'pattern' => 'gradual',
    'start_price' => $price,
    'target_price' => $price,
    'percent' => 0.0,
    'step' => 0.0,
];

function nowIso(): string {
    return date('Y-m-d H:i:s');
}

function jsonResponse(int $status, array $payload, array $extraHeaders = []): string {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) $body = '{"ok":false,"error":"json_encode_failed"}';

    $reasons = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ];
    $reason = $reasons[$status] ?? 'OK';

    $headers = [
        "HTTP/1.1 {$status} {$reason}",
        "Content-Type: application/json; charset=utf-8",
        "Content-Length: " . strlen($body),
        "Cache-Control: no-store, no-cache, must-revalidate, max-age=0",
        "Access-Control-Allow-Origin: *",
        "Access-Control-Allow-Methods: GET, POST, OPTIONS",
        "Access-Control-Allow-Headers: Content-Type, X-VZHB-Admin-Key",
        "Connection: close",
    ];

    foreach ($extraHeaders as $h) $headers[] = $h;

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function tickMarket(
    float &$price,
    float &$previousPrice,
    float &$updatedAt,
    array &$history,
    int $historyLimit,
    float $maxTickPercent,
    float $minPrice,
    float $maxPrice
): void {
    $previousPrice = $price;

    // Small random walk. Positive/negative movement is symmetric.
    $u = random_int(1, 1000000) / 1000000;
    $v = random_int(1, 1000000) / 1000000;
    $noise = sqrt(-2.0 * log(max($u, 1e-12))) * cos(2.0 * M_PI * $v);

    // Keep typical movement below the configured cap.
    $percent = max(-$maxTickPercent, min($maxTickPercent, $noise * ($maxTickPercent / 2.8)));
    $price *= (1.0 + ($percent / 100.0));

    if ($price < $minPrice) $price = $minPrice;
    if ($price > $maxPrice) $price = $maxPrice;

    $updatedAt = microtime(true);

    $history[] = [
        'price' => $price,
        'previous_price' => $previousPrice,
        'change_percent' => $previousPrice > 0 ? (($price - $previousPrice) / $previousPrice) * 100.0 : 0.0,
        'direction' => $price > $previousPrice ? 'up' : ($price < $previousPrice ? 'down' : 'flat'),
        'ts' => date('Y-m-d H:i:s'),
        'ts_unix' => $updatedAt,
    ];

    if (count($history) > $historyLimit) {
        array_shift($history);
    }
}

function appendHistory(
    float $price,
    float $previousPrice,
    float &$updatedAt,
    array &$history,
    int $historyLimit,
    string $source = 'random'
): void {
    $updatedAt = microtime(true);
    $history[] = [
        'price' => $price,
        'previous_price' => $previousPrice,
        'change_percent' => $previousPrice > 0 ? (($price - $previousPrice) / $previousPrice) * 100.0 : 0.0,
        'direction' => $price > $previousPrice ? 'up' : ($price < $previousPrice ? 'down' : 'flat'),
        'ts' => date('Y-m-d H:i:s'),
        'ts_unix' => $updatedAt,
        'source' => $source,
    ];
    if (count($history) > $historyLimit) array_shift($history);
}

function controlProgress(float $elapsed, int $duration, string $pattern): float {
    if ($duration <= 0) return 1.0;
    $t = max(0.0, min(1.0, $elapsed / $duration));
    switch ($pattern) {
        case 'drastic':
            // 80% of the movement happens during the first 20% of the duration.
            if ($t <= 0.20) return ($t / 0.20) * 0.80;
            return 0.80 + (($t - 0.20) / 0.80) * 0.20;
        case 'smooth':
            // Smooth ease-in/ease-out.
            return $t * $t * (3.0 - 2.0 * $t);
        case 'step':
            // Caller handles fixed step movement.
            return $t;
        case 'gradual':
        default:
            return $t;
    }
}

function controlledTick(
    float &$price,
    float &$previousPrice,
    float &$updatedAt,
    array &$history,
    int $historyLimit,
    float $minPrice,
    float $maxPrice,
    array &$control
): bool {
    if (empty($control['active'])) return false;

    $now = microtime(true);
    $elapsed = max(0.0, $now - (float)$control['started_at']);
    $duration = max(1, (int)$control['duration']);
    $pattern = (string)$control['pattern'];
    $direction = (string)$control['direction'];
    $sign = $direction === 'up' ? 1.0 : -1.0;
    $start = (float)$control['start_price'];
    $target = (float)$control['target_price'];

    $previousPrice = $price;

    if ((string)$control['mode'] === 'step') {
        $step = abs((float)$control['step']);
        if ($pattern === 'step') {
            // One fixed step per worker tick, capped at the final target.
            $desired = $start + ($sign * $step * floor($elapsed));
        } else {
            // For other patterns, distribute the total step movement over duration.
            $desired = $start + (($target - $start) * controlProgress($elapsed, $duration, $pattern));
        }
    } else {
        $desired = $start + (($target - $start) * controlProgress($elapsed, $duration, $pattern));
    }

    if ($elapsed >= $duration) {
        $desired = $target;
        $control['active'] = false;
    }

    $price = max($minPrice, min($maxPrice, $desired));
    appendHistory($price, $previousPrice, $updatedAt, $history, $historyLimit, 'control');
    return true;
}

function controlPublic(array $control): array {
    $out = $control;
    $out['active'] = !empty($control['active']);
    return $out;
}

function parseRequest(string $raw): array {
    $parts = explode("\r\n\r\n", $raw, 2);
    $head = $parts[0] ?? '';
    $body = $parts[1] ?? '';

    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';
    $tokens = preg_split('/\s+/', trim($requestLine));
    $method = strtoupper($tokens[0] ?? 'GET');
    $target = $tokens[1] ?? '/';

    $headers = [];
    foreach ($lines as $line) {
        $p = strpos($line, ':');
        if ($p === false) continue;
        $key = strtolower(trim(substr($line, 0, $p)));
        $value = trim(substr($line, $p + 1));
        $headers[$key] = $value;
    }

    // If Content-Length says more bytes are needed, caller may wait for them.
    $contentLength = (int)($headers['content-length'] ?? 0);

    return [$method, $target, $headers, $body, $contentLength];
}

function requestBodyJson(string $body): array {
    if ($body === '') return [];
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function publicMarket(float $price, float $previousPrice, float $updatedAt, array $history, int $limit, array $priceControl): array {
    $limit = max(1, min(600, $limit));
    $items = array_slice($history, -$limit);
    $change = $previousPrice > 0 ? (($price - $previousPrice) / $previousPrice) * 100.0 : 0.0;

    return [
        'ok' => true,
        'service' => 'VZHB Deplexo Persistent Worker V3',
        'symbol' => 'VZHB',
        'price' => $price,
        'previous_price' => $previousPrice,
        'change_percent' => $change,
        'direction' => $price > $previousPrice ? 'up' : ($price < $previousPrice ? 'down' : 'flat'),
        'updated_at' => date('Y-m-d H:i:s', (int)$updatedAt),
        'updated_at_unix' => $updatedAt,
        'history' => $items,
        'price_control' => controlPublic($priceControl),
    ];
}

$server = @stream_socket_server(
    "tcp://0.0.0.0:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if ($server === false) {
    fwrite(STDERR, "VZHB worker failed to bind port {$port}: {$errstr} ({$errno})\n");
    exit(1);
}

stream_set_blocking($server, false);

echo "VZHB worker listening on 0.0.0.0:{$port}\n";

$clients = [];

while (true) {
    $now = microtime(true);

    // Continuous market movement, independent of incoming HTTP requests.
    // Admin control temporarily overrides the original random movement.
    while (($now - $lastTick) >= 1.0) {
        if (!controlledTick(
            $price,
            $previousPrice,
            $updatedAt,
            $history,
            $historyLimit,
            $minPrice,
            $maxPrice,
            $priceControl
        )) {
            tickMarket(
                $price,
                $previousPrice,
                $updatedAt,
                $history,
                $historyLimit,
                $maxTickPercent,
                $minPrice,
                $maxPrice
            );
        }
        $lastTick += 1.0;
        $now = microtime(true);
    }

    $read = [$server];
    foreach ($clients as $id => $client) {
        $read[] = $client;
    }

    $write = null;
    $except = null;

    // Wake at least every ~200ms so the market loop stays alive.
    @stream_select($read, $write, $except, 0, 200000);

    foreach ($read as $sock) {
        if ($sock === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_timeout($client, 2);
                stream_set_blocking($client, false);
                $clients[(int)$client] = $client;
            }
            continue;
        }

        $id = (int)$sock;
        $raw = '';

        // Read enough for a normal HTTP request. Small API bodies are expected.
        $deadline = microtime(true) + 1.5;
        do {
            $chunk = @fread($sock, 8192);
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
            }

            if (strpos($raw, "\r\n\r\n") !== false) {
                [, , , $body, $contentLength] = parseRequest($raw);
                $headerLen = strpos($raw, "\r\n\r\n") + 4;
                $bodyBytes = strlen(substr($raw, $headerLen));
                if ($contentLength <= $bodyBytes) break;
            }

            if (feof($sock)) break;
            usleep(5000);
        } while (microtime(true) < $deadline);

        if ($raw === '') {
            @fclose($sock);
            unset($clients[$id]);
            continue;
        }

        [$method, $target, $headers, $body] = parseRequest($raw);

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string)(parse_url($target, PHP_URL_QUERY) ?? ''), $query);
        $response = '';

        if ($method === 'OPTIONS') {
            $response = "HTTP/1.1 204 No Content\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
                . "Access-Control-Allow-Headers: Content-Type, X-VZHB-Admin-Key\r\n"
                . "Content-Length: 0\r\nConnection: close\r\n\r\n";
            @fwrite($sock, $response);
            @fclose($sock);
            unset($clients[$id]);
            continue;
        }

        if ($method === 'GET' && ($path === '/' || $path === '/health')) {
            $response = jsonResponse(200, [
                'ok' => true,
                'service' => 'VZHB Deplexo Persistent Worker V3',
                'symbol' => 'VZHB',
                'status' => 'running',
                'price' => $price,
                'updated_at' => date('Y-m-d H:i:s', (int)$updatedAt),
                'history_count' => count($history),
                'uptime_mode' => 'persistent-1s',
            ]);
        } elseif ($method === 'GET' && $path === '/api/market') {
            $limit = isset($query['limit']) ? (int)$query['limit'] : 120;
            $response = jsonResponse(200, publicMarket(
                $price,
                $previousPrice,
                $updatedAt,
                $history,
                $limit,
                $priceControl
            ));
        } elseif ($method === 'POST' && $path === '/api/admin/control') {
            $providedKey = (string)($headers['x-vzhb-admin-key'] ?? '');
            if ($adminKey === '' || !hash_equals($adminKey, $providedKey)) {
                $response = jsonResponse(401, ['ok' => false, 'error' => 'invalid_admin_key']);
            } else {
                $data = requestBodyJson($body);
                $action = strtolower((string)($data['action'] ?? ''));

                if ($action === 'stop') {
                    $priceControl['active'] = false;
                    $priceControl['started_at'] = 0.0;
                    $response = jsonResponse(200, [
                        'ok' => true,
                        'action' => 'stop',
                        'price' => $price,
                        'price_control' => controlPublic($priceControl),
                    ]);
                } elseif ($action === 'start') {
                    $direction = strtolower((string)($data['direction'] ?? 'down'));
                    $mode = strtolower((string)($data['mode'] ?? 'percent'));
                    $pattern = strtolower((string)($data['pattern'] ?? 'gradual'));
                    $duration = (int)($data['duration'] ?? 30);

                    if (!in_array($direction, ['up', 'down'], true)) $direction = 'down';
                    if (!in_array($mode, ['percent', 'target', 'step'], true)) $mode = 'percent';
                    if (!in_array($pattern, ['gradual', 'drastic', 'smooth', 'step'], true)) $pattern = 'gradual';
                    $duration = max(1, min(86400, $duration));

                    // Every START is allowed even if a previous control is active.
                    // This deliberately lets admin switch pattern/mode without getting stuck.
                    $start = $price;
                    $sign = $direction === 'up' ? 1.0 : -1.0;
                    $target = $start;
                    $percent = 0.0;
                    $step = 0.0;

                    if ($mode === 'percent') {
                        $percent = abs((float)($data['percent'] ?? 0));
                        if (!is_finite($percent) || $percent <= 0) {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'percent_invalid']);
                        } else {
                            $target = $start * (1.0 + ($sign * $percent / 100.0));
                        }
                    } elseif ($mode === 'target') {
                        $target = (float)($data['target_price'] ?? 0);
                        if (!is_finite($target) || $target <= 0) {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'target_price_invalid']);
                        }
                    } else {
                        $step = abs((float)($data['step'] ?? 0));
                        if (!is_finite($step) || $step <= 0) {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'step_invalid']);
                        } else {
                            $target = $start + ($sign * $step * $duration);
                        }
                    }

                    if ($response === '') {
                        $target = max($minPrice, min($maxPrice, $target));
                        $priceControl = [
                            'active' => true,
                            'started_at' => microtime(true),
                            'duration' => $duration,
                            'direction' => $direction,
                            'mode' => $mode,
                            'pattern' => $pattern,
                            'start_price' => $start,
                            'target_price' => $target,
                            'percent' => $percent,
                            'step' => $step,
                        ];

                        $response = jsonResponse(200, [
                            'ok' => true,
                            'action' => 'start',
                            'price' => $price,
                            'price_control' => controlPublic($priceControl),
                        ]);
                    }
                } else {
                    $response = jsonResponse(400, ['ok' => false, 'error' => 'invalid_control_action']);
                }
            }
        } elseif ($method === 'POST' && $path === '/api/admin/price') {
            $providedKey = (string)($headers['x-vzhb-admin-key'] ?? '');
            if ($adminKey === '' || !hash_equals($adminKey, $providedKey)) {
                $response = jsonResponse(401, ['ok' => false, 'error' => 'invalid_admin_key']);
            } else {
                $data = requestBodyJson($body);
                $newPrice = isset($data['price']) ? (float)$data['price'] : 0.0;
                $note = isset($data['note']) ? (string)$data['note'] : 'admin_adjustment';

                if (!is_finite($newPrice) || $newPrice <= 0) {
                    $response = jsonResponse(400, ['ok' => false, 'error' => 'invalid_price']);
                } else {
                    $newPrice = max($minPrice, min($maxPrice, $newPrice));
                    $priceControl['active'] = false;
                    $previousPrice = $price;
                    $price = $newPrice;
                    $updatedAt = microtime(true);
                    $history[] = [
                        'price' => $price,
                        'previous_price' => $previousPrice,
                        'change_percent' => $previousPrice > 0 ? (($price - $previousPrice) / $previousPrice) * 100.0 : 0.0,
                        'direction' => $price > $previousPrice ? 'up' : ($price < $previousPrice ? 'down' : 'flat'),
                        'ts' => date('Y-m-d H:i:s'),
                        'ts_unix' => $updatedAt,
                        'source' => 'admin',
                        'note' => $note,
                    ];
                    if (count($history) > $historyLimit) array_shift($history);

                    $response = jsonResponse(200, [
                        'ok' => true,
                        'symbol' => 'VZHB',
                        'price' => $price,
                        'previous_price' => $previousPrice,
                        'note' => $note,
                        'updated_at' => date('Y-m-d H:i:s', (int)$updatedAt),
                    ]);
                }
            }
        } else {
            $response = jsonResponse(404, [
                'ok' => false,
                'error' => 'not_found',
                'path' => $path,
                'routes' => ['GET /', 'GET /health', 'GET /api/market?limit=120', 'POST /api/admin/control', 'POST /api/admin/price'],
            ]);
        }

        @fwrite($sock, $response);
        @fclose($sock);
        unset($clients[$id]);
    }
}
?>
