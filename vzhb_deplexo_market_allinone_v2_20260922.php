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
 * - supports temporary admin-controlled movement, then automatically returns to randomTick()
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
// Persistent M1 OHLC history. 10,080 candles = 7 days, enough for H4 aggregation.
$m1HistoryLimit = 10080;
$m1History = [];
$tickHistory = [];
$previousPrice = $price;
$lastEngineAt = time();
$priceControl = [
    'active' => false,
    'direction' => null,
    'mode' => null,
    'start_price' => $price,
    'target_price' => null,
    'percent' => null,
    'duration' => 0,
    'started_at' => 0,
    'ends_at' => 0,
    'step' => null,
    'pattern' => 'gradual',
];

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

function saveState(
    string $file,
    float $price,
    float $previousPrice,
    float $changePercent,
    array $history,
    array $m1History,
    array $priceControl = []
): void {
    $payload = [
        'symbol' => 'VZHB',
        'name' => 'VRILZHUB',
        'price' => round($price, 8),
        'previous_price' => round($previousPrice, 8),
        'change_percent' => round($changePercent, 8),
        'updated_at' => date('Y-m-d H:i:s'),
        // Keep recent raw ticks for compatibility/debugging.
        'history' => array_slice($history, -600),
        // Real engine-generated M1 OHLC history used by the chart.
        'm1_history' => array_slice($m1History, -10080),
        'price_control' => $priceControl,
    ];

    @file_put_contents(
        $file,
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
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
            'price_control' => [],
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
            'price_control' => [],
        ];
    }

    return [
        'price' => isset($data['price']) ? (float)$data['price'] : $fallbackPrice,
        'previous_price' => isset($data['previous_price']) ? (float)$data['previous_price'] : $fallbackPrice,
        'change_percent' => isset($data['change_percent']) ? (float)$data['change_percent'] : 0.0,
        'history' => isset($data['history']) && is_array($data['history']) ? $data['history'] : [],
        'price_control' => isset($data['price_control']) && is_array($data['price_control']) ? $data['price_control'] : [],
    ];
}


function makeBootstrapM1History(
    float $startPrice,
    int $count,
    float $maxTickPercent,
    float $minPrice,
    float $maxPrice
): array {
    /*
     * The VZHB market is an internal RNG market, not an external exchange.
     * When a fresh container has no persisted state, create a warm historical
     * series from the same market engine so every timeframe has real OHLC
     * data immediately instead of showing only 1-2 candles.
     */
    $out = [];
    $close = $startPrice;
    $startBucket = intdiv(time(), 60) * 60 - (($count - 1) * 60);

    for ($i = 0; $i < $count; $i++) {
        $open = $close;
        $high = $open;
        $low = $open;

        // Six engine steps per minute gives each M1 candle genuine OHLC movement.
        for ($j = 0; $j < 6; $j++) {
            [$next, , ] = randomTick($close, $maxTickPercent, $minPrice, $maxPrice);
            $close = $next;
            if ($close > $high) $high = $close;
            if ($close < $low) $low = $close;
        }

        $bucket = $startBucket + ($i * 60);
        $out[] = [
            'time' => $bucket,
            'timestamp' => $bucket,
            'open' => round($open, 8),
            'high' => round($high, 8),
            'low' => round($low, 8),
            'close' => round($close, 8),
        ];
    }

    return $out;
}

function updateM1Candle(array &$m1History, float $price, int $timestamp, int $limit): void {
    $bucket = intdiv($timestamp, 60) * 60;
    $lastIndex = count($m1History) - 1;

    if ($lastIndex >= 0 && (int)($m1History[$lastIndex]['time'] ?? -1) === $bucket) {
        $m1History[$lastIndex]['high'] = round(max(
            (float)$m1History[$lastIndex]['high'],
            $price
        ), 8);
        $m1History[$lastIndex]['low'] = round(min(
            (float)$m1History[$lastIndex]['low'],
            $price
        ), 8);
        $m1History[$lastIndex]['close'] = round($price, 8);
        $m1History[$lastIndex]['timestamp'] = $bucket;
        return;
    }

    $open = $lastIndex >= 0
        ? (float)$m1History[$lastIndex]['close']
        : $price;

    $m1History[] = [
        'time' => $bucket,
        'timestamp' => $bucket,
        'open' => round($open, 8),
        'high' => round(max($open, $price), 8),
        'low' => round(min($open, $price), 8),
        'close' => round($price, 8),
    ];

    if (count($m1History) > $limit) {
        $m1History = array_slice($m1History, -$limit);
    }
}

function controlledTick(float $currentPrice, array &$control, float $minPrice, float $maxPrice): array {
    /*
     * CONTROL ENGINE ONLY
     * - Every new START replaces the previous control completely.
     * - Pattern is evaluated on every tick, so switching pattern works
     *   without restarting the worker.
     * - When duration/target is reached, control is disabled and the
     *   caller automatically falls back to randomTick().
     */
    $now = time();

    $start = (float)($control['start_price'] ?? $currentPrice);
    $target = (float)($control['target_price'] ?? $currentPrice);
    $duration = max(1, (int)($control['duration'] ?? 1));
    $startedAt = (int)($control['started_at'] ?? $now);

    $pattern = strtolower((string)($control['pattern'] ?? 'gradual'));
    $mode = strtolower((string)($control['mode'] ?? 'target'));
    $direction = strtolower((string)($control['direction'] ?? 'up'));

    if (!in_array($pattern, ['gradual', 'drastic', 'smooth', 'step'], true)) {
        $pattern = 'gradual';
    }

    $elapsed = max(0, $now - $startedAt);
    $progress = min(1.0, $elapsed / $duration);

    /*
     * STEP is deliberately independent from the other easing patterns.
     * It moves by a fixed amount every market tick.
     */
    if ($pattern === 'step' || $mode === 'step') {
        $step = abs((float)($control['step'] ?? 0));

        if ($step <= 0) {
            // Invalid step must never freeze the market.
            $control['active'] = false;
            return randomTick($currentPrice, $GLOBALS['maxTickPercent'], $minPrice, $maxPrice);
        }

        $sign = $direction === 'down' ? -1.0 : 1.0;
        $next = $currentPrice + ($sign * $step);

        // Never pass the requested target.
        if ($direction === 'down' && $next <= $target) {
            $next = $target;
            $progress = 1.0;
        } elseif ($direction === 'up' && $next >= $target) {
            $next = $target;
            $progress = 1.0;
        }
    } else {
        /*
         * All non-step patterns use the same start/target pair.
         * This is important: changing gradual -> drastic -> smooth
         * never creates a second price engine.
         */
        switch ($pattern) {
            case 'drastic':
                // Large movement at the beginning, then progressively slows.
                $eased = 1.0 - pow(1.0 - $progress, 3.0);
                break;

            case 'smooth':
                // Smooth ease-in/ease-out.
                $eased = $progress * $progress * (3.0 - 2.0 * $progress);
                break;

            case 'gradual':
            default:
                // Constant/linear movement toward target.
                $eased = $progress;
                break;
        }

        $next = $start + (($target - $start) * $eased);
    }

    $next = max($minPrice, min($maxPrice, $next));

    /*
     * Finish exactly at target. Once inactive, the main loop uses
     * randomTick() on the following tick.
     */
    $targetReached =
        abs($next - $target) <= max(0.00000001, abs($target) * 0.00000001);

    if ($progress >= 1.0 || $targetReached) {
        $next = max($minPrice, min($maxPrice, $target));
        $control['active'] = false;
    }

    $actualPercent = $currentPrice > 0
        ? (($next - $currentPrice) / $currentPrice) * 100.0
        : 0.0;

    $actualDirection = $next > $currentPrice
        ? 'up'
        : ($next < $currentPrice ? 'down' : 'flat');

    return [$next, $actualPercent, $actualDirection];
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
if (!empty($loaded['price_control']) && is_array($loaded['price_control'])) {
    $priceControl = array_merge($priceControl, $loaded['price_control']);
}
$m1History = isset($loaded['m1_history']) && is_array($loaded['m1_history'])
    ? array_values(array_slice($loaded['m1_history'], -$m1HistoryLimit))
    : [];

// A fresh Deplexo container otherwise starts with only a couple of ticks.
// Warm it with 7 days of the SAME internal RNG engine, then keep it persistent.
if (count($m1History) < 120) {
    $m1History = makeBootstrapM1History(
        $price,
        $m1HistoryLimit,
        $maxTickPercent,
        $minPrice,
        $maxPrice
    );
    $price = (float)$m1History[count($m1History) - 1]['close'];
    $previousPrice = count($m1History) > 1
        ? (float)$m1History[count($m1History) - 2]['close']
        : $price;
    saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $m1History, $priceControl);
}

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
            if (!empty($priceControl['active'])) {
                [$newPrice, $changePercent, $direction] = controlledTick($price, $priceControl, $minPrice, $maxPrice);
            } else {
                [$newPrice, $changePercent, $direction] = randomTick($price, $maxTickPercent, $minPrice, $maxPrice);
            }

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

            // Every live tick updates only the active M1 candle.
            updateM1Candle($m1History, $price, time(), $m1HistoryLimit);

            saveState(
                $stateFile,
                $price,
                $previousPrice,
                $changePercent,
                $tickHistory,
                $m1History,
                $priceControl
            );
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
            $limit = isset($query['limit']) ? (int)$query['limit'] : 10080;
            $limit = max(1, min(12000, $limit));

            $latest = array_slice($tickHistory, -min($limit, $historyLimit));
            $m1Latest = array_slice($m1History, -min($limit, $m1HistoryLimit));

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
                // Stable M1 OHLC series for TradingView-style client aggregation.
                'candles_m1' => $m1Latest,
                'history_count' => count($m1History),
                'price_control' => $priceControl,
            ]);
        } elseif ($method === 'POST' && $path === '/api/admin/control') {
            $providedKey = $headers['x-vzhb-admin-key'] ?? '';
            if (!hash_equals($adminKey, (string)$providedKey)) {
                $response = jsonResponse(401, ['ok' => false, 'error' => 'invalid_admin_key']);
            } else {
                $payload = json_decode($body, true);
                if (!is_array($payload)) $payload = [];
                $action = strtolower((string)($payload['action'] ?? 'start'));

                if ($action === 'stop') {
                    // HARD STOP: clear the entire control state, not only `active`.
                    // The next market tick MUST use the original randomTick() path.
                    $priceControl = [
                        'active' => false,
                        'direction' => null,
                        'mode' => null,
                        'start_price' => round($price, 8),
                        'target_price' => null,
                        'percent' => null,
                        'duration' => 0,
                        'started_at' => 0,
                        'ends_at' => 0,
                        'step' => null,
                        'pattern' => 'gradual',
                    ];

                    // Force the next loop iteration to run immediately so the
                    // random engine resumes without waiting for the old schedule.
                    $lastTick = 0.0;

                    saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $m1History, $priceControl);
                    $response = jsonResponse(200, [
                        'ok' => true,
                        'message' => 'price control stopped; randomTick resumed',
                        'price_control' => $priceControl,
                        'random_tick' => true
                    ]);
                } else {
                    $direction = strtolower((string)($payload['direction'] ?? ''));
                    $mode = strtolower((string)($payload['mode'] ?? 'percent'));
                    $pattern = strtolower((string)($payload['pattern'] ?? 'gradual'));
                    if (!in_array($pattern, ['gradual','drastic','smooth','step'], true)) {
                        $pattern = 'gradual';
                    }
                    $duration = max(1, min(86400, (int)($payload['duration'] ?? 30)));
                    $target = null;

                    if ($mode === 'percent') {
                        $percent = (float)($payload['percent'] ?? $payload['value'] ?? 0);
                        if ($percent < 0) $percent = abs($percent);
                        if ($direction === 'down') $percent = -$percent;
                        elseif ($direction !== 'up') {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'direction_must_be_up_or_down']);
                            goto control_done;
                        }
                        $target = $price * (1.0 + ($percent / 100.0));
                    } elseif ($mode === 'target') {
                        $target = (float)($payload['target_price'] ?? 0);
                        if ($target <= 0) {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'target_price_required']);
                            goto control_done;
                        }
                        $direction = $target > $price ? 'up' : ($target < $price ? 'down' : 'flat');
                    } elseif ($mode === 'step') {
                        $step = abs((float)($payload['step'] ?? 0));
                        if ($step <= 0 || !in_array($direction, ['up','down'], true)) {
                            $response = jsonResponse(400, ['ok' => false, 'error' => 'step_and_direction_required']);
                            goto control_done;
                        }
                        $target = $direction === 'down' ? $minPrice : $maxPrice;
                    } else {
                        $response = jsonResponse(400, ['ok' => false, 'error' => 'invalid_mode']);
                        goto control_done;
                    }

                    $target = max($minPrice, min($maxPrice, (float)$target));
                    if ($target == $price) {
                        $priceControl['active'] = false;
                    } else {
                        $priceControl = [
                            'active' => true,
                            'direction' => $direction,
                            'mode' => $mode,
                            'start_price' => round($price, 8),
                            'target_price' => round($target, 8),
                            'percent' => $mode === 'percent' ? (float)($payload['percent'] ?? $payload['value'] ?? 0) : null,
                            'duration' => $duration,
                            'started_at' => time(),
                            'ends_at' => time() + $duration,
                            'step' => $mode === 'step' ? abs((float)$payload['step']) : null,
                            'pattern' => $pattern,
                        ];
                    }
                    saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $m1History, $priceControl);
                    $response = jsonResponse(200, ['ok' => true, 'message' => 'price control started', 'price_control' => $priceControl]);
                }
            }
control_done:
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
                    $priceControl['active'] = false;
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

                    updateM1Candle($m1History, $price, time(), $m1HistoryLimit);

                    saveState(
                        $stateFile,
                        $price,
                        $previousPrice,
                        $changePercent,
                        $tickHistory,
                        $m1History,
                        $priceControl
                    );

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
