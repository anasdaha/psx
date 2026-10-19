<?php
// Rule-based "outlook" picks with a back-test, built only from PSX closing prices.
//
// Back-testing 20 simple ideas on 13 months of PSX data (Sep 2025 to Oct 2026) found that
// momentum (buying recent winners) did NOT work on PSX, while a mild "bounce-back" effect did:
// stocks that fell most over the last few weeks tended to do slightly better than average next.
// The edge is small and unreliable, so the page shows the back-test result next to the picks.
//
//   Tomorrow: biggest 5-session fall
//   Week:     biggest 20-session fall
//   Month:    biggest 20-session fall, favouring stocks still above their 50-session average
//
// Stocks averaging under MIN_AVG_VOLUME shares a day over 20 sessions are skipped (too thin to trade).

const HIST_SESSIONS = 260;     // about one year of sessions to keep
const MAX_NEW_FILES = 40;      // closing files to download per run while back-filling history
const MIN_AVG_VOLUME = 50000;
const MODELS = [
    'tomorrow' => ['h' => 1,  'label' => 'next trading day'],
    'week'     => ['h' => 5,  'label' => 'next 5 trading days'],
    'month'    => ['h' => 21, 'label' => 'next 21 trading days'],
];

// Load up to HIST_SESSIONS closing files, oldest first, as [date => [sym => [close, ldcp, volume]]].
function load_history($cache, $symbols) {
    $missingFile = "$cache/missing-dates.json";
    $missing = is_file($missingFile) ? json_decode(file_get_contents($missingFile), true) : [];
    $budget = MAX_NEW_FILES;
    $hist = [];
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
        foreach ($symbols as $s) if (isset($rows[$s])) $keep[$s] = [$rows[$s]['close'], $rows[$s]['ldcp'], $rows[$s]['volume']];
        $hist[$date] = $keep;
    }
    file_put_contents($missingFile, json_encode($missing));
    ksort($hist);
    return $hist;
}

// Turn history into per-stock series: a dividend-safe price index (chained close/LDCP) and volumes.
function series($hist, $symbols, $live) {
    $P = []; $V = [];
    foreach ($symbols as $s) { $P[$s] = []; $V[$s] = []; }
    $append = function ($rows) use ($symbols, &$P, &$V) {
        foreach ($symbols as $s) {
            $prev = $P[$s] ? end($P[$s]) : 1.0;
            $r = 0.0; $v = 0;
            if (isset($rows[$s]) && $rows[$s][1] > 0 && $rows[$s][0] > 0) {
                $r = $rows[$s][0] / $rows[$s][1] - 1;
                if (abs($r) > 0.11) $r = 0.0;  // PSX's daily limit is about 10%; anything bigger is a data break
                $v = $rows[$s][2];
            }
            $P[$s][] = $prev * (1 + $r);
            $V[$s][] = $v;
        }
    };
    foreach ($hist as $rows) $append($rows);
    if ($live) $append($live);
    return [$P, $V];
}

function pct_rank($vals) {
    // Rank 0..1 across stocks (higher value = higher rank). Nulls are left out.
    $vals = array_filter($vals, function ($v) { return $v !== null; });
    asort($vals);
    $n = count($vals); $i = 0; $out = [];
    foreach ($vals as $k => $v) $out[$k] = $n > 1 ? $i++ / ($n - 1) : 0.5;
    return $out;
}

// Score every stock at session index $t (using data up to and including $t).
function scores($model, $P, $V, $t) {
    $back = function ($s, $h) use ($P, $t) { return $t - $h >= 0 ? $P[$s][$t] / $P[$s][$t - $h] - 1 : null; };
    $out = [];
    $ok = [];
    foreach ($P as $s => $series) {
        if ($t < 50) continue;
        $avgVol = array_sum(array_slice($V[$s], $t - 19, 20)) / 20;
        if ($avgVol < MIN_AVG_VOLUME) continue;
        $ok[] = $s;
    }
    if ($model === 'tomorrow') {
        $r = []; foreach ($ok as $s) $r[$s] = -$back($s, 5);
        return pct_rank($r);
    }
    $r = []; foreach ($ok as $s) $r[$s] = -$back($s, 20);
    $rank = pct_rank($r);
    if ($model === 'week') return $rank;
    $trend = [];
    foreach ($ok as $s) $trend[$s] = $P[$s][$t] / (array_sum(array_slice($P[$s], $t - 49, 50)) / 50) - 1;
    $tr = pct_rank($trend);
    foreach ($rank as $s => $v) $rank[$s] = $v + 0.5 * ($tr[$s] ?? 0);
    return $rank;
}

function top_n($score, $members, $n = 10) {
    $sc = array_intersect_key($score, array_flip($members));
    arsort($sc);
    return array_slice(array_keys($sc), 0, $n);
}

// How the same rule would have done in the past: picks made on each past session (non-overlapping),
// compared with every stock in the same group over the same period.
function backtest($model, $P, $V, $groups, $T) {
    $h = MODELS[$model]['h'];
    $tests = 0; $hits = 0; $picks = 0; $edge = 0.0; $from = null; $to = null;
    for ($t = 50; $t + $h < $T; $t += $h) {
        $score = scores($model, $P, $V, $t);
        foreach ($groups as $members) {
            $fwd = [];
            foreach ($members as $s) if (isset($score[$s])) $fwd[$s] = $P[$s][$t + $h] / $P[$s][$t] - 1;
            if (count($fwd) < 12) continue;
            $top = top_n($score, array_keys($fwd));
            $all = array_values($fwd); sort($all);
            $median = $all[intdiv(count($all), 2)];
            $avgAll = array_sum($all) / count($all);
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
    $hist = load_history($cache, $symbols);
    $dates = array_keys($hist);
    if (count($hist) < 120) return ['ready' => false, 'sessions' => count($hist), 'need' => 120];

    $live = null;
    if ($liveRows && $sessionDate !== end($dates)) { $live = $liveRows; $dates[] = $sessionDate; }
    [$P, $V] = series($hist, $symbols, $live);
    $T = count($dates);
    $sharia = array_values(array_filter($symbols, function ($s) use ($kmi) { return isset($kmi[$s]); }));
    $conv = array_values(array_diff($symbols, $sharia));

    // The back-test only changes when a new closing file arrives, so keep it for the day.
    $btFile = "$cache/backtest-" . array_key_last($hist) . ".json";
    if (is_file($btFile)) {
        $bt = json_decode(file_get_contents($btFile), true);
    } else {
        $histDates = array_keys($hist);
        [$hP, $hV] = series($hist, $symbols, null);
        $bt = [];
        foreach (MODELS as $m => $_) {
            $r = backtest($m, $hP, $hV, [$sharia, $conv], count($histDates));
            $r['from'] = $r['from'] !== null ? $histDates[$r['from']] : null;
            $r['to'] = $r['to'] !== null ? $histDates[$r['to']] : null;
            $bt[$m] = $r;
        }
        foreach (glob("$cache/backtest-*.json") as $old) @unlink($old);
        file_put_contents($btFile, json_encode($bt));
    }

    $t = $T - 1;
    $out = ['ready' => true, 'based_on' => $sessionDate, 'models' => []];
    foreach (MODELS as $m => $info) {
        $score = scores($m, $P, $V, $t);
        $lists = [];
        foreach (['sharia' => $sharia, 'conv' => $conv] as $g => $members) {
            $lists[$g] = array_map(function ($s) use ($P, $t, $kse, $m) {
                $r5 = round(($P[$s][$t] / $P[$s][$t - 5] - 1) * 100, 2);
                $r20 = round(($P[$s][$t] / $P[$s][$t - 20] - 1) * 100, 2);
                $ma50 = array_sum(array_slice($P[$s], $t - 49, 50)) / 50;
                return ['symbol' => $s, 'name' => $kse[$s]['name'],
                        'price' => $kse[$s]['current'] > 0 ? $kse[$s]['current'] : $kse[$s]['ldcp'],
                        'chg5' => $r5, 'chg20' => $r20, 'above_ma50' => $P[$s][$t] > $ma50];
            }, top_n($score, $members));
        }
        $out['models'][$m] = ['horizon' => $info['label'], 'picks' => $lists, 'backtest' => $bt[$m]];
    }
    return $out;
}
