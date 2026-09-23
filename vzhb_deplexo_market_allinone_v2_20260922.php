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
$movement = null;
$news = [];

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

function saveState(string $file, float $price, float $previousPrice, float $changePercent, array $history, ?array $movement = null, array $news = []): void {
    $payload = [
        'symbol' => 'VZHB',
        'name' => 'VRILZHUB',
        'price' => round($price, 8),
        'previous_price' => round($previousPrice, 8),
        'change_percent' => round($changePercent, 8),
        'updated_at' => date('Y-m-d H:i:s'),
        'history' => array_slice($history, -600),
        'movement' => $movement,
        'news' => array_slice($news, -50),
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
        'movement' => isset($data['movement']) && is_array($data['movement']) ? $data['movement'] : null,
        'news' => isset($data['news']) && is_array($data['news']) ? $data['news'] : [],
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
$movement = $loaded['movement'] ?? null;
$news = array_slice($loaded['news'] ?? [], -50);


function addTick(array &$tickHistory, float $newPrice, float $oldPrice, string $direction, string $source = 'engine', string $note = ''): float {
    $changePercent = $oldPrice > 0 ? (($newPrice - $oldPrice) / $oldPrice) * 100.0 : 0.0;
    $tickHistory[] = [
        'price' => round($newPrice, 8),
        'previous_price' => round($oldPrice, 8),
        'change_percent' => round($changePercent, 8),
        'direction' => $direction,
        'created_at' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'source' => $source,
        'note' => $note,
    ];
    return $changePercent;
}

function buildM1Candles(array $ticks, int $maxCandles = 10080): array {
    $groups = [];
    foreach ($ticks as $t) {
        if (!isset($t['timestamp'], $t['price'])) continue;
        $ts = (int)$t['timestamp'];
        $bucket = intdiv($ts, 60) * 60;
        $p = (float)$t['price'];
        if (!isset($groups[$bucket])) {
            $groups[$bucket] = ['timestamp'=>$bucket,'open'=>$p,'high'=>$p,'low'=>$p,'close'=>$p];
        } else {
            $groups[$bucket]['high'] = max($groups[$bucket]['high'], $p);
            $groups[$bucket]['low'] = min($groups[$bucket]['low'], $p);
            $groups[$bucket]['close'] = $p;
        }
    }
    ksort($groups, SORT_NUMERIC);
    return array_values(array_slice($groups, -$maxCandles));
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

    // One visible market step per second. During an admin movement, the price
    // walks toward the target instead of teleporting, so the candle visibly
    // grows/shrinks and the M1 OHLC records every step.
    if (($now - $lastTick) >= 1.0) {
        $steps = (int)floor($now - $lastTick);
        if ($steps > 5) $steps = 5;

        for ($i = 0; $i < $steps; $i++) {
            $source = 'engine';
            $note = '';
            $newPrice = $price;

            if (is_array($movement)) {
                // Admin-controlled movement. Every engine tick becomes a real
                // market tick, so the user candle records the actual path.
                $startAt = (float)($movement['start_at'] ?? microtime(true));
                $duration = max(1, (int)($movement['duration'] ?? 10));
                $target = (float)($movement['target_price'] ?? $price);
                $startPrice = (float)($movement['start_price'] ?? $price);
                $mode = (string)($movement['movement_type'] ?? 'linear');
                $elapsed = max(0.0, $now - $startAt);
                $progress = min(1.0, $elapsed / $duration);

                if ($mode === 'instant') {
                    $newPrice = $target;
                } elseif ($mode === 'drastic') {
                    // Fast at the beginning, then settles into the target.
                    $eased = 1.0 - pow(1.0 - $progress, 3.0);
                    $newPrice = $startPrice + (($target - $startPrice) * $eased);
                } elseif ($mode === 'step') {
                    // Explicit price movement per second. The final step is
                    // clamped to the exact target so it never overshoots.
                    $step = abs((float)($movement['step_value'] ?? 0));
                    $distance = $target - $startPrice;
                    if ($step <= 0.0) {
                        $step = abs($distance) / max(1, $duration);
                    }
                    $stepsDone = (int)floor($elapsed);
                    $moved = min(abs($distance), $step * $stepsDone);
                    $newPrice = $startPrice + ($distance >= 0 ? $moved : -$moved);
                } elseif ($mode === 'smooth') {
                    $eased = $progress * $progress * (3.0 - 2.0 * $progress);
                    $newPrice = $startPrice + (($target - $startPrice) * $eased);
                } else {
                    // Default: constant/linear movement.
                    $newPrice = $startPrice + (($target - $startPrice) * $progress);
                }

                if ($progress >= 1.0 || ($mode === 'step' && abs($newPrice - $target) < 0.00000001)) {
                    $newPrice = $target;
                    $note = (string)($movement['note'] ?? 'Admin market move');
                }
                $source = 'admin_move';
            } else {
                [$newPrice] = randomTick($price, $maxTickPercent, $minPrice, $maxPrice);
            }

            $newPrice = max($minPrice, min($maxPrice, $newPrice));
            $previousPrice = $price;
            $price = $newPrice;
            $direction = $price > $previousPrice ? 'up' : ($price < $previousPrice ? 'down' : 'flat');
            $changePercent = addTick($tickHistory, $price, $previousPrice, $direction, $source, $note);

            if (count($tickHistory) > $historyLimit) array_shift($tickHistory);

            if (is_array($movement)) {
                $targetNow = (float)($movement['target_price'] ?? $price);
                $done = abs($price - $targetNow) < 0.00000001;
                $elapsedNow = $now - (float)($movement['start_at'] ?? $now);
                if ($done || $elapsedNow >= max(1, (int)($movement['duration'] ?? 10))) {
                    $price = $targetNow;
                    $movement = null;
                }
            }

            saveState($stateFile, $price, $previousPrice, $changePercent, $tickHistory, $movement, $news);
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
            $limit = max(1, min($historyLimit, $limit));

            $latest = array_slice($tickHistory, -$limit);
            $candlesM1 = buildM1Candles($tickHistory, 10080);

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
                'candles_m1' => $candlesM1,
                'movement' => $movement,
            ]);
        } elseif ($method === 'GET' && $path === '/api/news') {
            $response = jsonResponse(200, ['ok' => true, 'news' => array_reverse($news)]);
        } elseif ($method === 'GET' && $path === '/api/admin/status') {
            $response = jsonResponse(200, [
                'ok' => true,
                'price' => round($price, 8),
                'movement' => $movement,
                'news_count' => count($news),
            ]);
        } elseif ($method === 'POST' && in_array($path, ['/api/admin/price','/api/admin/move','/api/admin/cancel','/api/admin/news'], true)) {
            $providedKey = $headers['x-vzhb-admin-key'] ?? '';
            if (!hash_equals($adminKey, (string)$providedKey)) {
                $response = jsonResponse(401, ['ok' => false, 'error' => 'invalid_admin_key']);
            } else {
                $payload = json_decode($body, true);
                if (!is_array($payload)) $payload = [];

                if ($path === '/api/admin/cancel') {
                    $movement = null;
                    $response = jsonResponse(200, ['ok'=>true,'message'=>'movement_cancelled','price'=>round($price,8)]);
                    saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $movement, $news);
                } elseif ($path === '/api/admin/news') {
                    $action = (string)($payload['action'] ?? 'create');
                    if ($action === 'delete') {
                        $id = (string)($payload['id'] ?? '');
                        $news = array_values(array_filter($news, fn($n) => (string)($n['id'] ?? '') !== $id));
                        $response = jsonResponse(200, ['ok'=>true,'news'=>$news]);
                    } else {
                        $title = trim((string)($payload['title'] ?? ''));
                        $content = trim((string)($payload['content'] ?? ''));
                        if ($title === '' || $content === '') {
                            $response = jsonResponse(400, ['ok'=>false,'error'=>'title_and_content_required']);
                        } else {
                            $item = ['id'=>bin2hex(random_bytes(6)),'title'=>substr($title,0,120),'content'=>substr($content,0,1000),'created_at'=>date('Y-m-d H:i:s')];
                            $news[] = $item;
                            $news = array_slice($news, -50);
                            saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $movement, $news);
                            $response = jsonResponse(201, ['ok'=>true,'news'=>$item]);
                        }
                    }
                } elseif ($path === '/api/admin/price') {
                    $newPrice = isset($payload['price']) ? (float)$payload['price'] : 0.0;
                    $note = isset($payload['note']) ? substr((string)$payload['note'], 0, 200) : 'Admin price adjustment';
                    if ($newPrice < $minPrice || $newPrice > $maxPrice) {
                        $response = jsonResponse(400, ['ok'=>false,'error'=>'price_out_of_range','min_price'=>$minPrice,'max_price'=>$maxPrice]);
                    } else {
                        $old = $price;
                        $previousPrice = $price;
                        $price = $newPrice;
                        $changePercent = $old > 0 ? (($price-$old)/$old)*100.0 : 0.0;
                        $direction = $price > $old ? 'up' : ($price < $old ? 'down' : 'flat');
                        addTick($tickHistory, $price, $old, $direction, 'admin', $note);
                        if (count($tickHistory) > $historyLimit) array_shift($tickHistory);
                        $movement = null;
                        saveState($stateFile, $price, $previousPrice, $changePercent, $tickHistory, $movement, $news);
                        $response = jsonResponse(200, ['ok'=>true,'message'=>'VZHB price adjusted instantly','old_price'=>round($old,8),'new_price'=>round($price,8),'change_percent'=>round($changePercent,8),'updated_at'=>date('Y-m-d H:i:s')]);
                    }
                } else {
                    // Admin movement supports percentage OR exact target price,
                    // plus four movement profiles: linear, drastic, smooth, step.
                    $duration = max(1, min(86400, (int)($payload['duration'] ?? 10)));
                    $mode = (string)($payload['mode'] ?? 'percent');
                    $movementType = (string)($payload['movement_type'] ?? 'linear');
                    if (!in_array($movementType, ['linear','drastic','smooth','step','instant'], true)) {
                        $movementType = 'linear';
                    }
                    $note = substr((string)($payload['note'] ?? 'Admin market movement'), 0, 200);
                    $startPrice = $price;
                    if ($mode === 'price') {
                        if (!isset($payload['target_price']) || !is_numeric($payload['target_price'])) {
                            $response = jsonResponse(400, ['ok'=>false,'error'=>'target_price_required']);
                            goto movement_done;
                        }
                        $target = (float)$payload['target_price'];
                    } else {
                        $percent = isset($payload['percent']) && is_numeric($payload['percent']) ? (float)$payload['percent'] : 0.0;
                        $target = $startPrice * (1.0 + ($percent / 100.0));
                    }
                    $target = max($minPrice, min($maxPrice, $target));
                    $distance = $target - $startPrice;
                    $stepValue = isset($payload['step_value']) && is_numeric($payload['step_value']) ? abs((float)$payload['step_value']) : 0.0;
                    if ($movementType === 'step' && $stepValue <= 0.0) {
                        $stepValue = abs($distance) / max(1, $duration);
                    }
                    $movement = [
                        'mode'=>$mode,
                        'movement_type'=>$movementType,
                        'direction'=>$distance > 0 ? 'up' : ($distance < 0 ? 'down' : 'flat'),
                        'start_price'=>round($startPrice,8),
                        'target_price'=>round($target,8),
                        'percent'=>round($startPrice > 0 ? (($target-$startPrice)/$startPrice)*100.0 : 0.0,8),
                        'duration'=>$duration,
                        'step_value'=>round($stepValue,8),
                        'start_at'=>microtime(true),
                        'note'=>$note,
                    ];
                    saveState($stateFile, $price, $previousPrice, 0.0, $tickHistory, $movement, $news);
                    $response = jsonResponse(200, ['ok'=>true,'message'=>'market movement started','movement'=>$movement,'current_price'=>round($price,8)]);
                    movement_done:;
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
