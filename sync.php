<?php
// Builds data.json for the PSX top-10 page from official PSX sources only:
//   https://dps.psx.com.pk/indices/KSE100      KSE-100 members with live price and volume
//   https://dps.psx.com.pk/indices/KMIALLSHR   KMI All Share members (the Sharia-compliant test)
//   https://dps.psx.com.pk/download/mkt_summary/YYYY-MM-DD.Z   daily closing file (for week / month bases)
//
// Run it from cron:      php /path/to/sync.php
// or from a web cron:    https://your-site/psx/sync.php?key=YOUR_SYNC_KEY
// The page also calls    sync.php?auto=1  which only syncs when data.json is older than the interval.

require __DIR__ . '/config.php';
require __DIR__ . '/predict.php';
@set_time_limit(300);
date_default_timezone_set('Asia/Karachi');

const BASE = 'https://dps.psx.com.pk';
const WEEK = 5;    // trading sessions back for "week"
const MONTH = 21;  // trading sessions back for "month"
$DIR = __DIR__;
$OUT = "$DIR/data.json";
$CACHE = "$DIR/cache";

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: application/json');
    $auto = isset($_GET['auto']);
    $keyOk = isset($_GET['key']) && hash_equals($SYNC_KEY, (string)$_GET['key']);
    if (!$auto && !$keyOk) { http_response_code(403); echo '{"ok":false,"error":"wrong key"}'; exit; }
    // Auto mode: do nothing while the data is still fresh.
    if (!$keyOk && is_file($OUT) && time() - filemtime($OUT) < $SYNC_MINUTES * 60) { echo '{"ok":true,"fresh":true}'; exit; }
}

@mkdir($CACHE, 0755, true);
$lock = fopen("$CACHE/sync.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { if (!$cli) echo '{"ok":true,"busy":true}'; exit; }

function fetch($url, $tries = 10) {
    // PSX drops many connections, so retry until there is a real answer.
    for ($i = 0; $i < $tries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Referer: ' . BASE . '/'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code == 200 && $body !== false && $body !== '') return $body;
        if ($code == 404) return null;
        usleep((1500 + $i * 500) * 1000);
    }
    throw new Exception("Could not reach $url");
}

function num($s) { return (float)str_replace([',', '%'], '', trim(strip_tags($s))); }

// Index page rows: SYMBOL, NAME, LDCP, CURRENT, CHANGE, CHANGE%, IDX WTG, IDX POINT, VOLUME, FREEFLOAT, MCAP
function index_rows($index) {
    $html = fetch(BASE . "/indices/$index");
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $html, $trs);
    $rows = [];
    foreach ($trs[1] as $tr) {
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr, $td);
        $c = $td[1];
        if (count($c) < 9) continue;
        // Take the symbol from the company link: the cell text gains XD/XB suffixes on ex-dates.
        if (preg_match('#href="/company/([^"]+)"#', $c[0], $m)) $sym = $m[1];
        else $sym = trim(strip_tags(preg_replace('#<div.*?</div>#s', '', $c[0])));
        $rows[$sym] = ['name' => html_entity_decode(trim(strip_tags($c[1]))), 'ldcp' => num($c[2]),
                       'current' => num($c[3]), 'volume' => (int)num($c[8])];
    }
    if (count($rows) < 50) throw new Exception("PSX returned too few rows for $index");
    return $rows;
}

function day_file($date, $cache) {
    $path = "$cache/$date.Z";
    if (is_file($path)) return $path;
    $blob = fetch(BASE . "/download/mkt_summary/$date.Z");
    if (!$blob || substr($blob, 0, 2) !== 'PK') return null;
    file_put_contents($path, $blob);
    return $path;
}

function parse_day($path) {
    $zip = new ZipArchive();
    $zip->open($path);
    $text = $zip->getFromIndex(0);
    $zip->close();
    $rows = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $p = explode('|', $line);
        if (count($p) < 10) continue;
        // Fields: date|symbol|sector|name|open|high|low|close|volume|ldcp
        $rows[$p[1]] = ['name' => trim($p[3]), 'sector' => $p[2], 'open' => (float)$p[4], 'high' => (float)$p[5], 'low' => (float)$p[6],
                        'close' => (float)$p[7], 'volume' => (int)$p[8], 'ldcp' => (float)$p[9]];
    }
    return $rows;
}

try {
    $kse = index_rows('KSE100');
    $kmi = index_rows('KMIALLSHR');

    // Last MONTH+1 closing files, oldest first.
    $days = [];
    $d = new DateTime('today');
    for ($n = 0; count($days) < MONTH + 1 && $n < 70; $n++, $d->modify('-1 day')) {
        if ((int)$d->format('N') >= 6) continue;
        $f = day_file($d->format('Y-m-d'), $CACHE);
        if ($f) $days[] = ['date' => $d->format('Y-m-d'), 'rows' => parse_day($f)];
    }
    $days = array_reverse($days);
    if (count($days) < MONTH + 1) throw new Exception('Not enough PSX closing files found');

    // Is the live index page a new session after the last closing file, or that same session?
    // If most stocks' live LDCP equals the last file's close, the market has moved on to a new day.
    $last = end($days)['rows'];
    $newer = 0; $same = 0;
    foreach ($kse as $s => $r) {
        if (!isset($last[$s])) continue;
        if (abs($r['ldcp'] - $last[$s]['close']) < 0.005) $newer++;
        elseif (abs($r['current'] - $last[$s]['close']) < 0.005) $same++;
    }
    $liveIsNewSession = $newer > $same;
    $sessionDate = $liveIsNewSession ? date('Y-m-d') : end($days)['date'];
    // Closing files that come before the live session.
    $prior = $liveIsNewSession ? $days : array_slice($days, 0, -1);
    $weekBase = $prior[count($prior) - WEEK];
    $monthBase = $prior[count($prior) - MONTH];

    $pct = function ($now, $base) { return $base > 0 ? round(($now / $base - 1) * 100, 2) : null; };
    $stocks = [];
    foreach ($kse as $sym => $r) {
        $now = $r['current'] > 0 ? $r['current'] : $r['ldcp'];
        $volSince = function ($k) use ($prior, $sym, $r) {
            $v = $r['volume'];
            foreach (array_slice($prior, -($k - 1)) as $day) $v += $day['rows'][$sym]['volume'] ?? 0;
            return $v;
        };
        $flags = [];
        foreach (array_slice($prior, -(MONTH - 1)) as $day) {
            $nm = str_replace('.', ' ', $day['rows'][$sym]['name'] ?? '');
            if (preg_match_all('/\b(XD|XB|XR)\b/', $nm, $m)) $flags = array_merge($flags, $m[1]);
        }
        $stocks[] = [
            'symbol' => $sym, 'name' => $r['name'], 'sharia' => isset($kmi[$sym]), 'price' => $now,
            'day' => $pct($now, $r['ldcp']),
            'week' => $pct($now, $weekBase['rows'][$sym]['close'] ?? 0),
            'month' => $pct($now, $monthBase['rows'][$sym]['close'] ?? 0),
            'vol_day' => $r['volume'], 'vol_week' => $volSince(WEEK), 'vol_month' => $volSince(MONTH),
            'corp_action' => implode(' ', array_unique($flags)),
        ];
    }

    // Predictions: a failure here must not stop the movers from updating.
    try {
        $liveRows = null;
        if ($liveIsNewSession) {
            $liveRows = [];
            foreach ($kse as $s => $r) $liveRows[$s] = [$r['current'] > 0 ? $r['current'] : $r['ldcp'], $r['ldcp'], $r['volume']];
        }
        $predictions = build_predictions($CACHE, $kse, $kmi, $liveRows, $sessionDate);
    } catch (Throwable $e) {
        $predictions = ['ready' => false, 'error' => $e->getMessage()];
    }

    $data = [
        'synced_at' => date('c'), 'sync_minutes' => $SYNC_MINUTES,
        'session_date' => $sessionDate, 'week_base' => $weekBase['date'], 'month_base' => $monthBase['date'],
        'stocks' => $stocks,
        'predictions' => $predictions,
    ];
    file_put_contents("$OUT.tmp", json_encode($data, JSON_PRETTY_PRINT));
    rename("$OUT.tmp", $OUT);

    // Keep the cache small: drop closing files older than 60 days.
    foreach (glob("$CACHE/*.Z") as $f) if (basename($f, '.Z') < date('Y-m-d', time() - 420 * 86400)) @unlink($f);

    $msg = "Synced " . count($stocks) . " stocks, session $sessionDate";
    if ($cli) echo "$msg\n"; else echo json_encode(['ok' => true, 'message' => $msg]);
} catch (Exception $e) {
    // Leave the last good data.json in place.
    if ($cli) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
