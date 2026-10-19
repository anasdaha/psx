<?php
// Multi-factor "outlook" picks with a back-test, built only from PSX daily files (open, high, low, close, volume).
//
// Testing 20 factors on 13 months of PSX data (Sep 2025 to Oct 2026) found that momentum and trend-following
// did NOT work on PSX. What did: calm stocks (low volatility, small daily ranges) beat jumpy ones at every
// horizon, recent fallers bounced back a little over 1 to 5 days, stocks near their 52-week high held up, and
// "lottery" stocks with a big one-day spike lagged. Each stock gets a 0-100 score from these five factors,
// ranked within its group (Sharia / non-Sharia), with weights tilted by horizon:
//
//   factor                         tomorrow  week  month
//   Calm: low 60-session volatility    1      1      1
//   Steady: small daily high-low range 1      1      1
//   Bounce: 5-session fall vs sector   1      1     0.5
//   No spike: small biggest 20-day gain 1     0.5     0
//   Near 52-week high                 0.5     1      1
//
// The edge is small: picks beat the typical stock a bit more often than chance, but their average return is
// close to the group's. The page shows the back-test next to the picks.
//
// Stocks averaging under MIN_AVG_VOLUME shares a day over 20 sessions are skipped (too thin to trade).

const HIST_SESSIONS = 260;     // about one year of sessions to keep
const MAX_NEW_FILES = 40;      // closing files to download per run while back-filling history
const MIN_AVG_VOLUME = 50000;
const FIRST_SESSION = 60;      // sessions of history a stock needs before it can be scored
const MODELS = [
    'tomorrow' => ['h' => 1,  'label' => 'next trading day',     'w' => ['calm' => 1, 'steady' => 1, 'bounce' => 1,   'nospike' => 1,   'high' => 0.5]],
    'week'     => ['h' => 5,  'label' => 'next 5 trading days',  'w' => ['calm' => 1, 'steady' => 1, 'bounce' => 1,   'nospike' => 0.5, 'high' => 1]],
    'month'    => ['h' => 21, 'label' => 'next 21 trading days', 'w' => ['calm' => 1, 'steady' => 1, 'bounce' => 0.5, 'nospike' => 0,   'high' => 1]],
];

// Load up to HIST_SESSIONS closing files, oldest first, as [date => [sym => [close, ldcp, volume, high, low]]],
// plus each symbol's sector code.
function load_history($cache, $symbols) {
    $missingFile = "$cache/missing-dates.json";
    $missing = is_file($missingFile) ? json_decode(file_get_contents($missingFile), true) : [];
    $budget = MAX_NEW_FILES;
    $hist = []; $sectors = [];
    $d = new DateTime('today');
    $today = $d->format('Y-m-d');
    for ($n = 0; count($hist) < HIST_SESSIONS && $n < 400; $n++, $d->modify('-1 day')) {
        if ((int)$d->format('N') >= 6) continue;
        $date = $d->format('Y-m-d');
        if (isset($missing[$date])) continue;
        $path = "$cache/$date.Z";
        if (!is_file($path)) {
            if ($budget-- <= 0) continue;
            try {
                $blob = fetch(BASE . "/download/mkt_summary/$date.Z", 6);
            } catch (Exception $e) {
                $budget = 0;  // PSX is struggling; try the rest on the next run
                continue;
            }
            if (!$blob || substr($blob, 0, 2) !== 'PK') {
                if ($date !== $today) $missing[$date] = 1;  // holiday: don't ask again
                continue;
            }
            file_put_contents($path, $blob);
        }
        $rows = parse_day($path);
        $keep = [];
        foreach ($symbols as $s) {
            if (!isset($rows[$s])) continue;
            $r = $rows[$s];
            $keep[$s] = [$r['close'], $r['ldcp'], $r['volume'], $r['high'], $r['low']];
            if (!isset($sectors[$s])) $sectors[$s] = $r['sector'];
        }
        $hist[$date] = $keep;
    }
    file_put_contents($missingFile, json_encode($missing));
    ksort($hist);
    return [$hist, $sectors];
}

// Turn history into per-stock series: a dividend-safe price index (chained close/LDCP), daily returns,
// volumes and daily high-low range as a share of the close (null when unknown, e.g. the live session).
function series($hist, $symbols, $live) {
    $S = ['P' => [], 'R' => [], 'V' => [], 'G' => []];
    foreach ($symbols as $s) foreach ($S as $k => $_) $S[$k][$s] = [];
    $append = function ($rows) use ($symbols, &$S) {
        foreach ($symbols as $s) {
            $prev = $S['P'][$s] ? end($S['P'][$s]) : 1.0;
            $r = 0.0; $v = 0; $g = null;
            if (isset($rows[$s]) && $rows[$s][1] > 0 && $rows[$s][0] > 0) {
                $row = $rows[$s];
                $r = $row[0] / $row[1] - 1;
                if (abs($r) > 0.11) $r = 0.0;  // PSX's daily limit is about 10%; anything bigger is a data break
                $v = $row[2];
                if (isset($row[3], $row[4]) && $row[3] >= $row[4] && $row[4] > 0) $g = ($row[3] - $row[4]) / $row[0];
            }
            $S['P'][$s][] = $prev * (1 + $r);
            $S['R'][$s][] = $r;
            $S['V'][$s][] = $v;
            $S['G'][$s][] = $g;
        }
    };
    foreach ($hist as $rows) $append($rows);
    if ($live) $append($live);
    return $S;
}

function pct_rank($vals) {
    // Rank 0..1 across stocks (higher value = higher rank). Nulls are left out.
    $vals = array_filter($vals, function ($v) { return $v !== null; });
    asort($vals);
    $n = count($vals); $i = 0; $out = [];
    foreach ($vals as $k => $v) $out[$k] = $n > 1 ? $i++ / ($n - 1) : 0.5;
    return $out;
}

function median($vals) {
    sort($vals);
    $n = count($vals);
    return $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
}

function stdev($vals) {
    $n = count($vals);
    $m = array_sum($vals) / $n;
    $ss = 0; foreach ($vals as $x) $ss += ($x - $m) ** 2;
    return sqrt($ss / ($n - 1));
}

// Raw factor values for every stock with enough history at session index $t (data up to and including $t).
// Higher is better for every factor. Also returns the plain numbers the page shows.
function factor_values($S, $sectors, $t) {
    if ($t < FIRST_SESSION) return [];
    $P = $S['P'];
    $r5 = [];
    foreach ($P as $s => $p) $r5[$s] = $p[$t] / $p[$t - 5] - 1;
    $bySector = [];
    foreach ($r5 as $s => $r) $bySector[$sectors[$s] ?? '?'][] = $r;
    $market = median(array_values($r5));
    $out = [];
    foreach ($P as $s => $p) {
        if (array_sum(array_slice($S['V'][$s], $t - 19, 20)) / 20 < MIN_AVG_VOLUME) continue;
        $peers = $bySector[$sectors[$s] ?? '?'];
        $vol60 = stdev(array_slice($S['R'][$s], $t - 59, 60));
        $ranges = array_filter(array_slice($S['G'][$s], $t - 19, 20), function ($g) { return $g !== null; });
        $high = max(array_slice($p, max(0, $t - 249), min($t + 1, 250)));
        $out[$s] = [
            'calm' => -$vol60,
            'steady' => $ranges ? -array_sum($ranges) / count($ranges) : null,
            'bounce' => -($r5[$s] - (count($peers) >= 3 ? median($peers) : $market)),
            'nospike' => -max(array_slice($S['R'][$s], $t - 19, 20)),
            'high' => $p[$t] / $high,
            // shown on the page
            'vol' => $vol60, 'range' => $ranges ? array_sum($ranges) / count($ranges) : null, 'r5' => $r5[$s], 'off_high' => $p[$t] / $high - 1,
        ];
    }
    return $out;
}

// Score 0..1 for each stock in $members: the weighted average of its factor ranks within that group.
function scores($model, $fv, $members) {
    $fv = array_intersect_key($fv, array_flip($members));
    $w = MODELS[$model]['w'];
    $score = array_fill_keys(array_keys($fv), 0.0);
    foreach ($w as $f => $wf) {
        if (!$wf) continue;
        $rank = pct_rank(array_map(function ($v) use ($f) { return $v[$f]; }, $fv));
        foreach ($score as $s => $_) $score[$s] += $wf * ($rank[$s] ?? 0.5);
    }
    $total = array_sum($w);
    foreach ($score as $s => $v) $score[$s] = $v / $total;
    return $score;
}

function top_n($score, $n = 10) {
    arsort($score);
    return array_slice(array_keys($score), 0, $n);
}

// How the same rule would have done in the past: picks made on each past session (non-overlapping),
// compared with every stock in the same group over the same period.
function backtest($model, $S, $sectors, $groups, $T) {
    $h = MODELS[$model]['h'];
    $P = $S['P'];
    $tests = 0; $hits = 0; $picks = 0; $edge = 0.0; $from = null; $to = null;
    for ($t = FIRST_SESSION; $t + $h < $T; $t += $h) {
        $fv = factor_values($S, $sectors, $t);
        foreach ($groups as $members) {
            $score = scores($model, $fv, $members);
            if (count($score) < 12) continue;
            $fwd = [];
            foreach ($score as $s => $_) $fwd[$s] = $P[$s][$t + $h] / $P[$s][$t] - 1;
            $top = top_n($score);
            $median = median(array_values($fwd));
            $avgAll = array_sum($fwd) / count($fwd);
            $avgTop = 0;
            foreach ($top as $s) { $avgTop += $fwd[$s]; if ($fwd[$s] > $median) $hits++; $picks++; }
            $edge += $avgTop / count($top) - $avgAll;
            $tests++;
            if ($from === null) $from = $t;
            $to = $t + $h;
        }
    }
    return ['tests' => $tests, 'hit_rate' => $picks ? round($hits / $picks * 100, 1) : null,
            'avg_edge' => $tests ? round($edge / $tests * 100, 2) : null, 'from' => $from, 'to' => $to];
}

function build_predictions($cache, $kse, $kmi, $liveRows, $sessionDate) {
    $symbols = array_keys($kse);
    [$hist, $sectors] = load_history($cache, $symbols);
    $dates = array_keys($hist);
    if (count($hist) < 120) return ['ready' => false, 'sessions' => count($hist), 'need' => 120];

    $live = null;
    if ($liveRows && $sessionDate !== end($dates)) { $live = $liveRows; $dates[] = $sessionDate; }
    $S = series($hist, $symbols, $live);
    $T = count($dates);
    $sharia = array_values(array_filter($symbols, function ($s) use ($kmi) { return isset($kmi[$s]); }));
    $conv = array_values(array_diff($symbols, $sharia));

    // The back-test only changes when a new closing file arrives, so keep it for the day.
    $btFile = "$cache/backtest-v2-" . array_key_last($hist) . ".json";
    if (is_file($btFile)) {
        $bt = json_decode(file_get_contents($btFile), true);
    } else {
        $histDates = array_keys($hist);
        $hS = series($hist, $symbols, null);
        $bt = [];
        foreach (MODELS as $m => $_) {
            $r = backtest($m, $hS, $sectors, [$sharia, $conv], count($histDates));
            $r['from'] = $r['from'] !== null ? $histDates[$r['from']] : null;
            $r['to'] = $r['to'] !== null ? $histDates[$r['to']] : null;
            $bt[$m] = $r;
        }
        foreach (glob("$cache/backtest-*.json") as $old) @unlink($old);
        file_put_contents($btFile, json_encode($bt));
    }

    $t = $T - 1;
    $fv = factor_values($S, $sectors, $t);
    $out = ['ready' => true, 'based_on' => $sessionDate, 'models' => []];
    foreach (MODELS as $m => $info) {
        $lists = [];
        foreach (['sharia' => $sharia, 'conv' => $conv] as $g => $members) {
            $score = scores($m, $fv, $members);
            $lists[$g] = array_map(function ($s) use ($fv, $score, $kse) {
                $f = $fv[$s];
                return ['symbol' => $s, 'name' => $kse[$s]['name'],
                        'price' => $kse[$s]['current'] > 0 ? $kse[$s]['current'] : $kse[$s]['ldcp'],
                        'score' => (int)round($score[$s] * 100),
                        'chg5' => round($f['r5'] * 100, 2), 'off_high' => round($f['off_high'] * 100, 2),
                        'volatility' => round($f['vol'] * 100, 2), 'range' => $f['range'] !== null ? round($f['range'] * 100, 2) : null];
            }, top_n($score));
        }
        $out['models'][$m] = ['horizon' => $info['label'], 'weights' => $info['w'], 'picks' => $lists, 'backtest' => $bt[$m]];
    }
    return $out;
}
