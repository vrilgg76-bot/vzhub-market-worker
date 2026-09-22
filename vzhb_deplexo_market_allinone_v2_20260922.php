<?php
/**
 * VZHB Deplexo All-in-One Market Server
 * New standalone VZHB market engine + HTTP API.
 *
 * Runs as a PHP CLI process:
 *   php vzhb_deplexo_market_allinone_v2_20260922.php
 *
 * The same process:
 * - moves VZHB every second
 * - keeps recent ticks in memory
 * - serves HTTP JSON API
 * - supports protected admin price adjustment
 *
 * Environment variables:
 *   PORT=3000
 *   ADMIN_KEY=change-this-key
 *   START_PRICE=1000
 *   MAX_TICK_PERCENT=0.25
 *   MIN_PRICE=1
 *   MAX_PRICE=1000000000
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$port = (int)(getenv('PORT') ?: 3000);
if ($port < 1 || $port > 65535) {
    $port = 3000;
}

$adminKey = (string)(getenv('ADMIN_KEY') ?: 'CHANGE_ME_VZHB_ADMIN_KEY_2026');
$price = (float)(getenv('START_PRICE') ?: 1000);
$maxTickPercent = (float)(getenv('MAX_TICK_PERCENT') ?: 0.25);
$minPrice = (float)(getenv('MIN_PRICE') ?: 1);
$maxPrice = (float)(getenv('MAX_PRICE') ?: 1000000000);

if ($price < $minPrice) $price = $minPrice;
if ($price > $maxPrice) $price = $maxPrice;
if ($maxTickPercent <= 0) $maxTickPercent = 0.25;

$stateFile = __DIR__ . DIRECTORY_SEPARATOR . 'vzhb_runtime_state.json';
$historyLimit = 600;
$tickHistory = [];
$previousPrice = $price;
$lastEngineAt = time();

function jsonResponse(int $status, array $data): string {
    $reason = [
        200 => 'OK',
        201 => 'Created',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ][$status] ?? 'OK';

    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($body === false) {
        $body = '{"ok":false,"error":"json_encode_failed"}';
        $status = 500;
        $reason = 'Internal Server Error';
    }

    return "HTTP/1.1 {$status} {$reason}\r\n"
         . "Content-Type: application/json; charset=utf-8\r\n"
         . "Access-Control-Allow-Origin: *\r\n"
         . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
         . "Access-Control-Allow-Headers: Content-Type, X-VZHB-Admin-Key\r\n"
         . "Cache-Control: no-store, no-cache, must-revalidate\r\n"
         . "Content-Length: " . strlen($body) . "\r\n"
         . "Connection: close\r\n\r\n"
         . $body;
}

function saveState(string $file, float $price, float $previousPrice, float $changePercent, array $history): void {
    $payload = [
        'symbol' => 'VZHB',
        'name' => 'VRILZHUB',
        'price' => round($price, 8),
        'previous_price' => round($previousPrice, 8),
        'change_percent' => round($changePercent, 8),
        'updated_at' => date('Y-m-d H:i:s'),
        'history' => array_slice($history, -600),
    ];

    @file_put_contents(
        $file,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function loadState(string $file, float $fallbackPrice): array {
    if (!is_file($file)) {
        return [
            'price' => $fallbackPrice,
            'previous_price' => $fallbackPrice,
            'change_percent' => 0.0,
            'history' => [],
        ];
    }

    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'price' => $fallbackPrice,
            'previous_price' => $fallbackPrice,
            'change_percent' => 0.0,
            'history' => [],
        ];
    }

    return [
        'price' => isset($data['price']) ? (float)$data['price'] : $fallbackPrice,
        'previous_price' => isset($data['previous_price']) ? (float)$data['previous_price'] : $fallbackPrice,
        'change_percent' => isset($data['change_percent']) ? (float)$data['change_percent'] : 0.0,
        'history' => isset($data['history']) && is_array($data['history']) ? $data['history'] : [],
    ];
}

function randomTick(float $currentPrice, float $maxTickPercent, float $minPrice, float $maxPrice): array {
    // Slight upward/downward bias is intentionally absent: every tick is independent.
    $random = mt_rand(-1000000, 1000000) / 1000000;
    $percent = $random * $maxTickPercent;

    $next = $currentPrice * (1.0 + ($percent / 100.0));
    if ($next < $minPrice) $next = $minPrice;
    if ($next > $maxPrice) $next = $maxPrice;

    $actualPercent = $currentPrice > 0
        ? (($next - $currentPrice) / $currentPrice) * 100.0
        : 0.0;

    $direction = $next > $currentPrice ? 'up' : ($next < $currentPrice ? 'down' : 'flat');

    return [$next, $actualPercent, $direction];
}

$loaded = loadState($stateFile, $price);
$price = max($minPrice, min($maxPrice, (float)$loaded['price']));
$previousPrice = (float)$loaded['previous_price'];
$tickHistory = array_slice($loaded['history'], -$historyLimit);

$server = @stream_socket_server(
    "tcp://0.0.0.0:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if (!$server) {
    fwrite(STDERR, "VZHB server failed to listen on port {$port}: {$errstr} ({$errno})\n");
    exit(1);
}

stream_set_blocking($server, false);

fwrite(STDOUT, "VZHB all-in-one server started on 0.0.0.0:{$port}\n");
fwrite(STDOUT, "Price: " . number_format($price, 8, '.', '') . " | Tick: every 1 second\n");

$lastTick = microtime(true);
$clients = [];

while (true) {
    $now = microtime(true);

    // Price engine: one tick approximately every second.
    if (($now - $lastTick) >= 1.0) {
        $steps = (int)floor($now - $lastTick);
        if ($steps > 5) $steps = 5; // avoid a giant catch-up after a long pause

        for ($i = 0; $i < $steps; $i++) {
            [$newPrice, $changePercent, $direction] =
                randomTick($price, $maxTickPercent, $minPrice, $maxPrice);

            $previousPrice = $price;
            $price = $newPrice;

            $tickHistory[] = [
                'price' => round($price, 8),
                'previous_price' => round($previousPrice, 8),
                'change_percent' => round($changePercent, 8),
                'direction' => $direction,
                'created_at' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
            ];

            if (count($tickHistory) > $historyLimit) {
                array_shift($tickHistory);
            }

            saveState($stateFile, $price, $previousPrice, $changePercent, $tickHistory);
        }

        $lastTick = $now;
    }

    // Wait briefly for incoming HTTP clients without blocking the market loop.
    $read = [$server];
    foreach ($clients as $client) {
        $read[] = $client;
    }

    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, 100000);

    foreach ($read as $sock) {
        if ($sock === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client) {
                stream_set_blocking($client, false);
                $clients[(int)$client] = $client;
            }
            continue;
        }

        $key = (int)$sock;
        $request = @stream_get_contents($sock);

        if ($request === false || trim($request) === '') {
            @fclose($sock);
            unset($clients[$key]);
            continue;
        }

        $lines = preg_split("/\r\n|\n|\r/", $request);
        $requestLine = $lines[0] ?? '';
        [$method, $target] = array_pad(preg_split('/\s+/', trim($requestLine), 3), 2, '/');

        $headers = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$h, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($h))] = trim($v);
            }
        }

        $body = '';
        $separator = strpos($request, "\r\n\r\n");
        if ($separator !== false) {
            $body = substr($request, $separator + 4);
        } else {
            $separator = strpos($request, "\n\n");
            if ($separator !== false) {
                $body = substr($request, $separator + 2);
            }
        }

        $parsed = parse_url($target);
        $path = $parsed['path'] ?? '/';
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        if ($method === 'OPTIONS') {
            $response = "HTTP/1.1 204 No Content\r\n"
                      . "Access-Control-Allow-Origin: *\r\n"
                      . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
                      . "Access-Control-Allow-Headers: Content-Type, X-VZHB-Admin-Key\r\n"
                      . "Connection: close\r\n\r\n";
        } elseif ($method === 'GET' && ($path === '/' || $path === '/health')) {
            $response = jsonResponse(200, [
                'ok' => true,
                'service' => 'VZHB Deplexo All-in-One',
                'symbol' => 'VZHB',
                'status' => 'running',
                'price' => round($price, 8),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($method === 'GET' && $path === '/api/market') {
            $limit = isset($query['limit']) ? (int)$query['limit'] : 120;
            $limit = max(1, min(300, $limit));

            $latest = array_slice($tickHistory, -$limit);

            $change = $previousPrice > 0
                ? (($price - $previousPrice) / $previousPrice) * 100.0
                : 0.0;

            $response = jsonResponse(200, [
                'ok' => true,
                'market' => [
                    'symbol' => 'VZHB',
                    'name' => 'VRILZHUB',
                    'price' => round($price, 8),
                    'previous_price' => round($previousPrice, 8),
                    'change_percent' => round($change, 8),
                    'status' => 'open',
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                'ticks' => $latest,
            ]);
        } elseif ($method === 'POST' && $path === '/api/admin/price') {
            $providedKey = $headers['x-vzhb-admin-key'] ?? '';
            if (!hash_equals($adminKey, (string)$providedKey)) {
                $response = jsonResponse(401, [
                    'ok' => false,
                    'error' => 'invalid_admin_key',
                ]);
            } else {
                $payload = json_decode($body, true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $newPrice = isset($payload['price']) ? (float)$payload['price'] : 0.0;
                $note = isset($payload['note']) ? substr((string)$payload['note'], 0, 200) : 'Admin price adjustment';

                if ($newPrice < $minPrice || $newPrice > $maxPrice) {
                    $response = jsonResponse(400, [
                        'ok' => false,
                        'error' => 'price_out_of_range',
                        'min_price' => $minPrice,
                        'max_price' => $maxPrice,
                    ]);
                } else {
                    $old = $price;
                    $previousPrice = $price;
                    $price = $newPrice;

                    $changePercent = $old > 0 ? (($price - $old) / $old) * 100.0 : 0.0;
                    $direction = $price > $old ? 'up' : ($price < $old ? 'down' : 'flat');

                    $tickHistory[] = [
                        'price' => round($price, 8),
                        'previous_price' => round($old, 8),
                        'change_percent' => round($changePercent, 8),
                        'direction' => $direction,
                        'created_at' => date('Y-m-d H:i:s'),
                        'timestamp' => time(),
                        'source' => 'admin',
                        'note' => $note,
                    ];

                    if (count($tickHistory) > $historyLimit) {
                        array_shift($tickHistory);
                    }

                    saveState($stateFile, $price, $previousPrice, $changePercent, $tickHistory);

                    $response = jsonResponse(200, [
                        'ok' => true,
                        'message' => 'VZHB price adjusted',
                        'old_price' => round($old, 8),
                        'new_price' => round($price, 8),
                        'change_percent' => round($changePercent, 8),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } else {
            $response = jsonResponse(404, [
                'ok' => false,
                'error' => 'not_found',
                'path' => $path,
            ]);
        }

        @fwrite($sock, $response);
        @fclose($sock);
        unset($clients[$key]);
    }
}
?>
