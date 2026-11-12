<?php
/* Timetable: run windows and the warnings drawn from them. Pure functions over the job list from Collect.php, so the
 * tests can feed them example data. Nothing here reads the server.
 *
 * A job is an array: id, title, owner, category, schedule (type cron|event|daemon|none), impact (tags), duration
 * (sec, basis, note), link. A run window is [job id, start, end) in unix seconds, server local time. */

require_once __DIR__ . '/Cron.php';

final class TtAnalyse
{
    /** Jobs that run more often than this per run day are background work: listed, never drawn as bars. */
    public const FREQUENT = 24;
    /** Two starts this close together count as "the same minute" for the start-clash rule. */
    public const SAME_START = 60;
    /** A cluster: this many jobs starting within CLUSTER_SPAN seconds. */
    public const CLUSTER_MIN = 3;
    public const CLUSTER_SPAN = 300;

    /** Parsed cron sets per job id (only cron jobs that parse). */
    public static function prepare(array &$jobs): void
    {
        foreach ($jobs as &$j) {
            $s = &$j['schedule'];
            if (($s['type'] ?? '') !== 'cron') continue;
            try {
                $s['parsed'] = TtCron::parse($s['expr']);
                $s['perDay'] = TtCron::runsPerRunDay($s['parsed']);
                $s['text'] ??= TtCron::describe($s['parsed'], $s['guard'] ?? null, $s['macro'] ?? null);
                // a short job that runs more than hourly is a strip; a heavy one up to every 30 minutes still gets its bars, so overlaps with it are found
                $j['frequent'] = $s['perDay'] > self::FREQUENT && !(self::heavy($j) && $s['perDay'] <= 2 * self::FREQUENT);
            } catch (InvalidArgumentException $e) {
                $s['error'] = $e->getMessage();
                $s['text'] = 'cannot be read: ' . $e->getMessage();
                $j['frequent'] = false;
            }
            unset($s);
        }
        unset($j);
    }

    /** Run windows of all drawable jobs that touch [$from, $to). */
    public static function windows(array $jobs, int $from, int $to, int $capPerJob = 20000): array
    {
        $out = [];
        foreach ($jobs as $j) {
            $s = $j['schedule'];
            if (($s['type'] ?? '') !== 'cron' || empty($s['parsed']) || !empty($j['frequent'])) continue;
            $dur = max(60, (int)($j['duration']['sec'] ?? 60));
            foreach (TtCron::expand($s['parsed'], $from - $dur, $to, $capPerJob, $s['guard'] ?? null, $s['every7Base'] ?? null) as $t) {
                if ($t + $dur > $from) $out[] = [$j['id'], $t, $t + $dur];
            }
        }
        usort($out, fn($a, $b) => $a[1] <=> $b[1] ?: strcmp($a[0], $b[0]));
        return $out;
    }

    /** Next start of a cron job after $now, or null. */
    public static function nextRun(array $j, int $now): ?int
    {
        $s = $j['schedule'];
        if (($s['type'] ?? '') !== 'cron' || empty($s['parsed'])) return null;
        $r = TtCron::expand($s['parsed'], $now, $now + 400 * 86400, 1, $s['guard'] ?? null, $s['every7Base'] ?? null);
        return $r[0] ?? null;
    }

    /** All warnings for the jobs, looking $days ahead from $now. $ctx: startArray (yes|no), spindownDelay (minutes,
     *  0 = never). Each warning: rule, severity, title, jobs (ids), window (start,end of the first case), count,
     *  why (one line), example (one line), links. */
    public static function warnings(array $jobs, int $now, int $days = 35, array $ctx = []): array
    {
        $byId = [];
        foreach ($jobs as $j) $byId[$j['id']] = $j;
        $to = $now + $days * 86400;
        $win = self::windows($jobs, $now, $to);
        // a line that is in two crontabs is one job running twice: the duplicate finding names it, pairs with itself do not
        $alias = [];
        foreach ($ctx['duplicates'] ?? [] as [$a, $b]) if (isset($byId[$a], $byId[$b])) $alias[$b] = $alias[$a] ?? $a;
        $same = fn(string $a, string $b) => ($alias[$a] ?? $a) === ($alias[$b] ?? $b);
        $startAt = [];
        foreach ($win as [$id, $s, $e]) $startAt[$id][$s] = true;
        $repeat = self::parityRepeats($jobs, $now, $to);
        $warn = [];
        $add = function (string $rule, string $a, string $b, int $s, int $e) use (&$warn) {
            $k = "$rule|$a|$b";
            if (!isset($warn[$k])) $warn[$k] = ['rule' => $rule, 'jobs' => [$a, $b], 'window' => [$s, $e], 'count' => 0, 'starts' => []];
            $warn[$k]['count']++;
            $warn[$k]['starts'][] = $s;
        };
        foreach (self::overlaps($win, $byId) as [$rule, $a, $b, $s, $e]) {
            if (($rule !== 'self' && $same($a, $b)) || ($rule === 'self' && isset($repeat[$a]))) continue;
            $add($rule, $a, $b, $s, $e);
        }
        foreach ($repeat as $id => $r) $warn["parity-repeat|$id"] = ['rule' => 'parity-repeat', 'jobs' => [$id], 'window' => $r['window'], 'count' => $r['count'], 'starts' => $r['starts'],
            'vars' => ['{dur}' => self::dur((int)$byId[$id]['duration']['sec']), '{gap}' => self::dur($r['gap'])]];
        // Mover Tuning skips a scheduled mover that starts while a check runs: the overlap is then a skipped run, not a slow one
        foreach ($warn as &$w) {
            if ($w['rule'] !== 'mover-parity' || empty($byId[$w['jobs'][1]]['skipsDuringCheck'])) continue;
            $inside = true;
            foreach ($w['starts'] as $t) if (!isset($startAt[$w['jobs'][1]][$t])) $inside = false;
            if ($inside) { $w['skipped'] = true; $w['vars'] = ['{setting}' => $byId[$w['jobs'][1]]['skipsDuringCheck']['setting']]; }
        }
        unset($w);
        // starts in the same minute: a job of unknown weight next to a heavy one
        $starts = [];
        foreach ($win as [$id, $s, $e]) if ($s >= $now) $starts[] = [$id, $s];
        $n = count($starts);
        for ($i = 0; $i < $n; $i++) {
            for ($k = $i + 1; $k < $n && $starts[$k][1] - $starts[$i][1] < self::SAME_START; $k++) {
                $a = $byId[$starts[$i][0]]; $b = $byId[$starts[$k][0]];
                if ($same($a['id'], $b['id'])) continue;
                $ua = self::unknownWeight($a); $ub = self::unknownWeight($b);
                if (($ua && self::heavy($b)) || ($ub && self::heavy($a))) {
                    [$x, $y] = $ua ? [$b['id'], $a['id']] : [$a['id'], $b['id']];
                    if (!isset($warn["overlap-any|$x|$y"]) && !isset($warn["overlap-any|$y|$x"])) $add('same-start', $x, $y, $starts[$i][1], $starts[$i][1] + 60);
                }
            }
        }
        // clusters: many jobs starting within a few minutes of each other
        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $ids = [];
            for ($k = $i; $k < $n && $starts[$k][1] - $starts[$i][1] <= self::CLUSTER_SPAN; $k++) $ids[$alias[$starts[$k][0]] ?? $starts[$k][0]] = true;
            if (count($ids) >= self::CLUSTER_MIN) {
                $key = implode(',', array_keys($ids)) . '@' . date('H:i', $starts[$i][1]);
                $clusters[$key] ??= ['rule' => 'cluster', 'jobs' => array_keys($ids), 'window' => [$starts[$i][1], $starts[$i][1] + self::CLUSTER_SPAN], 'count' => 0, 'starts' => []];
                $clusters[$key]['count']++;
                $clusters[$key]['starts'][] = $starts[$i][1];
                while ($i + 1 < $n && $starts[$i + 1][1] - $starts[$i][1] <= self::CLUSTER_SPAN) $i++;
            }
        }
        // keep only the largest cluster per start time
        $bestAt = [];
        foreach ($clusters as $c) {
            $t = date('H:i', $c['window'][0]);
            if (!isset($bestAt[$t]) || count($c['jobs']) > count($bestAt[$t]['jobs'])) $bestAt[$t] = $c;
        }
        $out = array_values($warn);
        // a cluster whose jobs are already named pair by pair in overlap findings adds nothing
        $paired = [];
        foreach ($warn as $w) if ($w['rule'] !== 'same-start' && count($w['jobs']) > 1) { $paired[$w['jobs'][0] . '|' . $w['jobs'][1]] = true; $paired[$w['jobs'][1] . '|' . $w['jobs'][0]] = true; }
        foreach ($bestAt as $c) {
            $all = true;
            foreach ($c['jobs'] as $x) foreach ($c['jobs'] as $y) if ($x !== $y && !isset($paired["$x|$y"])) $all = false;
            if (!$all) $out[] = $c;
        }
        // configuration rules that need no window
        $needArray = array_values(array_map(fn($j) => $j['id'], array_filter($jobs, fn($j) => in_array('needs_array', $j['impact'] ?? [], true) && ($j['schedule']['type'] ?? '') !== 'none')));
        if (($ctx['startArray'] ?? 'yes') === 'no' && $needArray)
            $out[] = ['rule' => 'array-manual', 'jobs' => $needArray, 'window' => null, 'count' => count($needArray)];
        $spin = (int)($ctx['spindownDelay'] ?? 0);
        if ($spin > 0) {
            foreach ($jobs as $j) {
                if (!in_array('wakes_disks', $j['impact'] ?? [], true) || ($j['schedule']['type'] ?? '') !== 'cron' || empty($j['schedule']['parsed'])) continue;
                $runs = count(TtCron::expand($j['schedule']['parsed'], $now, $now + 7 * 86400, 10000, $j['schedule']['guard'] ?? null));
                if ($runs / 7 >= 4) $out[] = ['rule' => 'spinup', 'jobs' => [$j['id']], 'window' => null, 'count' => (int)round($runs / 7), 'spindown' => $spin];
            }
        }
        foreach ($ctx['unloaded'] ?? [] as $u) $out[] = ['rule' => 'not-loaded', 'jobs' => [], 'window' => null, 'count' => 1, 'file' => $u];
        foreach (self::moverBlocked($jobs, $win) as $b) $out[] = $b;
        foreach ($ctx['duplicates'] ?? [] as [$a, $b]) {
            if (!isset($byId[$a], $byId[$b])) continue;
            $src = fn($j) => $j['source']['live'] ?? ($j['source']['file'] . ($j['source']['line'] ? ':' . $j['source']['line'] : ''));
            $out[] = ['rule' => 'duplicate', 'jobs' => [$a, $b], 'window' => null, 'count' => 2, 'sev' => !empty($byId[$a]['light']) && !empty($byId[$b]['light']) ? 'info' : 'warning',
                'vars' => ['{a}' => $src($byId[$a]), '{b}' => $src($byId[$b])]];
        }
        if (!empty($ctx['staleLive'])) {
            $ids = [];
            foreach ($ctx['staleLive'] as $l) foreach ($jobs as $j) if (($j['source']['live'] ?? '') === $l['file'] . ':' . $l['line']) $ids[] = $j['id'];
            $n = count($ctx['staleLive']);
            $out[] = ['rule' => 'stale-live', 'jobs' => array_values(array_unique($ids)), 'window' => null, 'count' => $n,
                'vars' => ['{lines}' => $n === 1 ? 'A crontab line is' : "$n crontab lines are", '{them}' => $n === 1 ? 'this line runs' : 'these lines run',
                    '{list}' => implode('; ', array_map(fn($l) => $l['file'] . ':' . $l['line'] . ' "' . $l['text'] . '"', $ctx['staleLive']))]];
        }
        // a server put to sleep by S3 Sleep runs no cron job until it wakes
        foreach ($jobs as $s3) {
            if (($s3['category'] ?? '') !== 'sleep' || empty($s3['sleep'])) continue;
            $skipH = array_flip($s3['sleep']['stopHour']); $skipD = array_flip($s3['sleep']['stopDay']);
            $hit = []; $during = [];
            $off = fn(int $t) => isset($skipH[(int)date('G', $t)]) || isset($skipD[(int)date('w', $t)]);
            foreach ($win as [$id, $s, $e]) {
                if ($s < $now || !empty($byId[$id]['light'])) continue;
                if (!$off($s)) { $hit[$id] ??= $s; continue; }
                // started while sleep was excluded: does it still run (15 minutes or more) into an hour when sleep is allowed?
                for ($t = strtotime(date('Y-m-d H:00:00', $s)) + 3600; $t < $e - 900; $t += 3600) {
                    if (!$off($t)) { $during[$id] ??= [$t, $e]; break; }
                }
            }
            if ($hit) $out[] = ['rule' => 's3-sleep', 'jobs' => array_merge([$s3['id']], array_keys($hit)), 'window' => null, 'count' => count($hit)];
            if ($during) {
                uasort($during, fn($x, $y) => $x[0] <=> $y[0]);
                $heavy = (bool)array_filter(array_keys($during), fn($id) => in_array($byId[$id]['category'], ['parity', 'backup'], true));
                $first = reset($during);
                $out[] = ['rule' => 's3-sleep-during-run', 'jobs' => array_merge([$s3['id']], array_keys($during)), 'window' => null, 'count' => count($during),
                    'sev' => $heavy ? 'warning' : 'info', 'vars' => ['{first}' => $byId[array_key_first($during)]['title'], '{at}' => date('H:i', $first[0]),
                        '{jobs}' => count($during) === 1 ? 'A job is' : count($during) . ' jobs are', '{they}' => count($during) === 1 ? 'It starts' : 'They start']];
            }
        }
        foreach ($out as &$w) {
            self::explain($w, $byId);
            if (!empty($w['starts']) && $w['count'] > 6) $w['pattern'] = self::pattern($w['starts'], $days);
            unset($w['starts']);
        }
        unset($w);
        $sev = ['warning' => 0, 'info' => 1];
        usort($out, fn($a, $b) => ($sev[$a['severity']] <=> $sev[$b['severity']]) ?: (($a['window'][0] ?? PHP_INT_MAX) <=> ($b['window'][0] ?? PHP_INT_MAX)));
        return $out;
    }

    /** Pairs of run windows that overlap where it matters: [rule, job a, job b, start, end]. Sweep line over windows
     *  sorted by start; a window that ends when the next starts does not overlap it. */
    public static function overlaps(array $win, array $byId): array
    {
        $out = [];
        $active = [];
        foreach ($win as [$id, $s, $e]) {
            $active = array_values(array_filter($active, fn($w) => $w[2] > $s));
            foreach ($active as [$id2, $s2, $e2]) {
                if ($id2 === $id) {
                    $out[] = ['self', $id, $id, $s, min($e, $e2)];
                    continue;
                }
                $rule = self::pairRule($byId[$id2], $byId[$id]);
                if ($rule === null) continue;
                [$a, $b] = self::order($byId[$id2], $byId[$id]);
                $out[] = [$rule, $a, $b, max($s, $s2), min($e, $e2)];
            }
            $active[] = [$id, $s, $e];
        }
        return $out;
    }

    /** warning or info, as the warning list gives it; conditional jobs (a parity window that only runs while a check
     *  is unfinished) lower a warning to info. */
    public static function severity(string $rule, array $a, array $b): string
    {
        $info = ['trim-parity', 'mover-mover', 'overlap-any', 'same-start', 'cluster', 'spinup', 's3-sleep', 'pool-mover', 'pool-backup', 'scrub-balance'];
        if (in_array($rule, $info, true) || !empty($a['conditional']) || !empty($b['conditional'])) return 'info';
        return 'warning';
    }

    public static function heavy(array $j): bool
    {
        return (bool)array_intersect(['array_io', 'pool_io', 'stops_containers'], $j['impact'] ?? []);
    }

    private static function unknownWeight(array $j): bool
    {
        return in_array('unknown', $j['impact'] ?? [], true);
    }

    private static function has(array $j, string $tag): bool
    {
        return in_array($tag, $j['impact'] ?? [], true);
    }

    /** The rule that applies when two jobs run at the same time, or null when that is harmless. */
    public static function pairRule(array $a, array $b): ?string
    {
        $cats = [$a['category'], $b['category']];
        sort($cats);
        $pair = implode('+', $cats);
        $map = ['mover+parity' => 'mover-parity', 'parity+parity' => 'parity-parity', 'mover+mover' => 'mover-mover',
            'backup+mover' => 'backup-mover', 'backup+parity' => 'backup-parity', 'parity+trim' => 'trim-parity',
            'backup+update' => 'containers-twice', 'update+update' => 'containers-twice', 'backup+backup' => 'containers-twice'];
        // a parity check and its own pause/resume window are one check, not two
        if ($pair === 'parity+parity' && (!empty($a['conditional']) || !empty($b['conditional']))) return null;
        if ($pair === 'pool+pool' && ($a['pool'] ?? 'a') === ($b['pool'] ?? 'b')) return ($a['poolOp'] ?? '') !== ($b['poolOp'] ?? '') && isset($a['poolOp'], $b['poolOp']) ? 'scrub-balance' : 'pool-pool';
        // a scrub or balance of a pool or of an array disk next to a parity check (a pause/resume window lowers it to info)
        if ($pair === 'parity+pool') return 'scrub-parity';
        if ($pair === 'mover+pool') return 'pool-mover';
        if ($pair === 'backup+pool') return 'pool-backup';
        if (isset($map[$pair])) return $map[$pair];
        if (self::has($a, 'stops_containers') && self::has($b, 'stops_containers')) return 'containers-twice';
        if (self::has($a, 'array_io') && self::has($b, 'array_io')) return 'overlap-any';
        return null;
    }

    /** Parity checks whose next scheduled start comes less than 12 hours after the previous run ends (or before it
     *  ends): several days ticked for a check that takes about a day. Increment windows are left out. id => first case. */
    public static function parityRepeats(array $jobs, int $from, int $to): array
    {
        $out = [];
        foreach ($jobs as $j) {
            $s = $j['schedule'];
            if ($j['category'] !== 'parity' || !empty($j['conditional']) || ($s['type'] ?? '') !== 'cron' || empty($s['parsed'])) continue;
            $d = max(60, (int)($j['duration']['sec'] ?? 0));
            $t = TtCron::expand($s['parsed'], $from - $d, $to, 2000, $s['guard'] ?? null, $s['every7Base'] ?? null);
            for ($i = 0; $i + 1 < count($t); $i++) {
                $gap = $t[$i + 1] - ($t[$i] + $d);
                if ($gap >= 12 * 3600 || $t[$i + 1] < $from) continue;
                $out[$j['id']] ??= ['window' => [$t[$i + 1], $t[$i + 1] + $d], 'count' => 0, 'starts' => [], 'gap' => max(0, $gap)];
                $out[$j['id']]['count']++;
                $out[$j['id']]['starts'][] = $t[$i + 1];
            }
        }
        return $out;
    }

    /** Movers that Mover Tuning holds back while a check runs, where that means days without a move: a check that
     *  takes over a day, or (Mover Tuning before 2026.11.05a) a check split into nightly parts, paused in between. */
    public static function moverBlocked(array $jobs, array $win): array
    {
        $out = [];
        foreach ($jobs as $m) {
            if (empty($m['skipsDuringCheck'])) continue;
            $mStarts = array_column(array_filter($win, fn($w) => $w[0] === $m['id']), 1);
            foreach ($jobs as $p) {
                if ($p['category'] !== 'parity' || ($p['schedule']['type'] ?? '') !== 'cron') continue;
                $vars = ['{setting}' => $m['skipsDuringCheck']['setting']];
                if (!empty($p['conditional'])) {
                    if ($m['skipsDuringCheck']['pausedCounts'] !== true) continue;
                    $out[] = ['rule' => 'mover-blocked-by-check', 'jobs' => [$m['id'], $p['id']], 'window' => null, 'count' => 1, 'variant' => 'paused', 'vars' => $vars];
                    continue;
                }
                if ((int)($p['duration']['sec'] ?? 0) <= 86400) continue;
                foreach ($win as [$id, $s, $e]) {
                    if ($id !== $p['id']) continue;
                    $skipped = count(array_filter($mStarts, fn($t) => $t >= $s && $t < $e));
                    if (!$skipped) continue;
                    $out[] = ['rule' => 'mover-blocked-by-check', 'jobs' => [$m['id'], $p['id']], 'window' => [$s, $e], 'count' => 1, 'variant' => 'long',
                        'vars' => $vars + ['{dur}' => self::dur($e - $s), '{n}' => $skipped === 1 ? 'one run' : "$skipped runs in a row"]];
                    break;
                }
            }
        }
        return $out;
    }

    /** "Stops 12 containers at 03:00: plex, sonarr, ... (+4 more)" */
    public static function stopsText(array $names, ?string $at = null, int $show = 8): string
    {
        $n = count($names);
        $list = implode(', ', array_slice($names, 0, $show)) . ($n > $show ? ' (+' . ($n - $show) . ' more)' : '');
        return 'Stops ' . $n . ' container' . ($n === 1 ? '' : 's') . ($at !== null ? " at $at" : '') . ': ' . $list;
    }

    public static function dur(int $sec): string
    {
        $m = (int)round($sec / 60);
        if ($m < 60) return max(1, $m) . ' min';
        $h = intdiv($m, 60); $m %= 60;
        return $h . ' h' . ($m ? " $m min" : '');
    }

    /** Which job is named first in a pair warning: the heavier, then the one with the lower id. */
    private static function order(array $a, array $b): array
    {
        $rank = ['parity' => 0, 'backup' => 1, 'mover' => 2, 'update' => 3, 'trim' => 4, 'pool' => 5];
        $ra = $rank[$a['category']] ?? 9; $rb = $rank[$b['category']] ?? 9;
        if ($ra === $rb) return strcmp($a['id'], $b['id']) <= 0 ? [$a['id'], $b['id']] : [$b['id'], $a['id']];
        return $ra < $rb ? [$a['id'], $b['id']] : [$b['id'], $a['id']];
    }

    /** Severity, title, why and example for a warning. Short: one line of reason, one of example. */
    private static function explain(array &$w, array $byId): void
    {
        $t = fn($i) => isset($w['jobs'][$i]) ? ($byId[$w['jobs'][$i]]['title'] ?? $w['jobs'][$i]) : '';
        $texts = [
            'mover-parity' => ['warning', '%1$s and %2$s run at the same time',
                'A running check does not stop the scheduled mover: it is not blocked, only the Move button on the Main page is greyed out. Both read and write the array disks, so each slows the other down; a check that normally takes a night can run into the day.',
                'Ex. move the mover to a time the check is done, or let it skip while a check runs (the Mover Tuning plugin has a setting for that).'],
            'mover-parity:skipped' => ['info', '%2$s is skipped while %1$s runs',
                'Mover Tuning is set not to start the scheduled mover during a check ("{setting}" is No), so this run is skipped instead of slowing the check; the files stay on the cache until the next run after the check.',
                'Ex. nothing to change if the cache can hold the files of one more day; otherwise move the mover to a time the check is done.'],
            'mover-blocked-by-check:long' => ['warning', '%1$s does not run while %2$s is unfinished',
                'Mover Tuning skips the scheduled mover while a check runs ("{setting}" is No), and this check takes about {dur}, so the mover misses {n}; new files pile up on the cache until the check is done.',
                'Ex. make sure the cache can hold a few days of new files, or set "{setting}" to Yes and let Parity Check Tuning pause the check while the mover runs.'],
            'mover-blocked-by-check:paused' => ['warning', '%1$s does not run while %2$s is unfinished',
                'Mover Tuning skips the scheduled mover while a check is in progress ("{setting}" is No), and this version also counts a paused check, so a check split into nightly parts can keep the mover off for days while the cache fills up.',
                'Ex. update Mover Tuning (from 2026.11.05a it moves while the check is paused), or set "{setting}" to Yes.'],
            'scrub-parity' => ['warning', '%2$s runs during %1$s',
                'A scrub or balance reads (a balance also rewrites) every block of its disks while the check reads every array disk; together both slow down, and the advice on the Unraid forum is to keep scrubs away from parity checks.',
                'Ex. run the scrub or balance a day before or after the check.'],
            'scrub-balance' => ['info', '%1$s and %2$s run on the same pool at the same time',
                'Both go through the whole pool and the balance also rewrites it, so each takes much longer than alone.',
                'Ex. balance on the 1st of the month and scrub on the 15th.'],
            'parity-repeat' => ['warning', '%1$s starts again soon after it finishes',
                'It is set to start on days close together, and a check takes about {dur} here, so the next start comes {gap} after the previous run ends (or while it still runs); the scheduler starts a check on every ticked day and does not know the last one has just finished.',
                'Ex. tick one day only, for example the first Sunday of the month, so the check runs once per period.'],
            's3-sleep-during-run' => ['info', '{jobs} still running when S3 Sleep may put the server to sleep',
                '{they} in hours you excluded from sleep but are still running later, ex. {first} at {at}; S3 Sleep decides by drive and network activity and has no check for a running job, and users report the server sleeping in the middle of a parity check.',
                'Ex. exclude the hours until the job usually ends in the S3 Sleep settings, or start the job earlier.'],
            'duplicate' => ['warning', '%1$s is in the crontab twice',
                'The same command is in {a} and in {b}; crond starts each line on its own, so the job runs twice, and two runs of the same job at once can trip over each other (ex. two backups writing to the same folder).',
                'Ex. keep the line the plugin writes and remove the copy added by hand.'],
            'stale-live' => ['info', '{lines} in no schedule file on the flash drive',
                '{list}. Unraid rebuilds this crontab from the .cron files on the flash drive (at boot, and when a plugin or settings page saves a schedule), so {them} until then and not after.',
                'Ex. a line added by hand, or left by a plugin that removed its file without rebuilding the crontab; if it should keep running, set it up as a User Script.'],
            'backup-mover' => ['warning', '%1$s stops containers while %2$s runs',
                'The backup stops containers and copies their appdata while the mover may be moving files of the same shares; both compete for the same disks and the backup takes longer, so the containers stay down longer.',
                'Ex. start the backup an hour after the mover usually finishes.'],
            'backup-parity' => ['warning', '%2$s stops containers during %1$s',
                'The backup reads appdata and writes the archive while the check reads every array disk; both slow down and the containers stay stopped for longer.',
                'Ex. back up on a night the check does not run, or before the check starts.'],
            'trim-parity' => ['info', '%2$s runs during %1$s',
                'TRIM is short, but on some SSDs it pauses writes while it runs; a pool that also takes writes during the check (mover, Docker) waits.',
                'Ex. run TRIM a few hours before or after the check.'],
            'containers-twice' => ['warning', '%1$s and %2$s both stop or restart containers',
                'Two jobs that stop and start containers at the same time can leave a container stopped, or update it while it is being backed up.',
                'Ex. let the auto update run after the backup has started the containers again.'],
            'mover-mover' => ['info', '%1$s and %2$s move files at the same time',
                'Two movers at once share the cache disks and the array writes, so both take longer.',
                'Ex. space them out if both have much to move.'],
            'parity-parity' => ['warning', '%1$s and %2$s overlap',
                'Two checks at once read the same kind of disks at full speed; both take much longer.',
                'Ex. give each its own night.'],
            'overlap-any' => ['info', '%1$s and %2$s overlap',
                'Both read or write the array disks, so each slows the other down.',
                'Ex. spread them out if either feels slow.'],
            'pool-pool' => ['warning', '%1$s and %2$s run on the same disks at the same time',
                'A balance and a scrub of the same pool cannot run together (btrfs refuses the second), and two scrubs or balances at once read and rewrite the same disks, so each takes far longer.',
                'Ex. give the scrub and the balance of one pool their own nights.'],
            'pool-mover' => ['info', '%1$s overlaps %2$s',
                'The mover writes to the pools and disks that hold the shares it moves; if it uses this pool or disk, the pool maintenance and the mover slow each other down.',
                'Ex. run the pool job on a night the mover has little to move.'],
            'pool-backup' => ['info', '%1$s overlaps %2$s',
                'The backup stops containers and copies appdata, usually kept on a pool; if it is this pool, the backup takes longer and the containers stay down longer.',
                'Ex. start the backup after the pool job has finished.'],
            'self' => ['warning', '%1$s can still be running when its next run starts',
                'Its runs take longer than the time between them; the next run then either waits, is skipped, or runs twice at once, depending on the job.',
                'Ex. run it less often, or check whether its runs really take this long.'],
            'same-start' => ['info', '%2$s starts in the same minute as %1$s',
                'What the second job does is not known here; if it reads or writes the same disks, both slow down.',
                'Ex. start a user script a few minutes later, or at a quiet hour.'],
            'cluster' => ['info', '%3$d jobs start within %4$d minutes',
                'Many jobs at the same time compete for disks and CPU; times like 00:00 or 04:00 are the defaults of many plugins.',
                'Ex. move the ones you can to a quieter time.'],
            'array-manual' => ['warning', 'The array does not start by itself after a reboot',
                'Disk settings say the array is not started at boot; until you start it, jobs that need it fail or skip their run.',
                'Ex. after a power cut at night the mover and the backup do nothing until someone starts the array.'],
            's3-sleep' => ['info', '%3$d jobs may find the server asleep',
                'S3 Sleep puts the server to sleep when its drives are idle, outside the hours and days you excluded; a job that is due while it sleeps does not run.',
                'Ex. exclude the hours of your nightly jobs in the S3 Sleep settings, or move the jobs into those hours.'],
            'not-loaded' => ['info', 'A schedule on the flash drive is not in the crontab',
                '%6$s: %7$s. A line that is not in the crontab never runs.',
                'Ex. a .cron file left behind by a removed plugin; if the job should run, set it up again on its settings page.'],
            'spinup' => ['info', '%1$s wakes the disks about %3$d times a day',
                'Disks spin down after %5$s idle, and a job that touches them that often keeps waking them; each spin-up costs time and wear.',
                'Ex. run it less often, or set the job to run only when there is work.'],
        ];
        $key = $w['rule'] . (!empty($w['skipped']) ? ':skipped' : '') . (isset($w['variant']) ? ':' . $w['variant'] : '');
        [$sev, $title, $why, $ex] = $texts[$key];
        if (isset($w['sev'])) $sev = $w['sev'];
        $spin = isset($w['spindown']) ? self::spinWords((int)$w['spindown']) : '';
        $span = $w['window'] ? (int)round(($w['window'][1] - $w['window'][0]) / 60) : 0;
        $w['severity'] = $sev;
        $w['title'] = sprintf($title, $t(0), count($w['jobs']) > 1 ? $t(1) : '', $w['rule'] === 'cluster' ? count($w['jobs']) : $w['count'], $span, $spin);
        $loc = isset($w['file']) ? $w['file']['file'] . ':' . $w['file']['line'] : '';
        $w['why'] = sprintf($why, '', '', 0, 0, $spin, $loc, $w['file']['why'] ?? '');
        $w['example'] = $ex;
        $vars = $w['vars'] ?? [];
        $w['title'] = strtr($w['title'], $vars); $w['why'] = strtr($w['why'], $vars); $w['example'] = strtr($w['example'], $vars);
        unset($w['vars'], $w['sev'], $w['variant'], $w['skipped']);
        // a backup finding names the containers the backup stops, the link users miss between restarts and the backup
        if (in_array($w['rule'], ['backup-mover', 'backup-parity', 'containers-twice', 'pool-backup', 'duplicate'], true)) {
            foreach (array_unique($w['jobs']) as $id) if (!empty($byId[$id]['stops'])) { $w['why'] .= ' ' . $byId[$id]['title'] . ': ' . self::stopsText($byId[$id]['stops']) . '.'; break; }
        }
        foreach ($w['jobs'] as $id) {
            if (!empty($byId[$id]['conditional']) && !in_array($w['rule'], ['array-manual', 'mover-blocked-by-check'], true)) {
                if ($w['severity'] === 'warning') $w['severity'] = 'info';
                $w['why'] .= ' ' . $byId[$id]['title'] . ': ' . strtolower($byId[$id]['conditional'][0]) . substr($byId[$id]['conditional'], 1);
            }
        }
    }

    /** The pattern in a long list of start times, in words: ex. 24 starts a day at :17 -> "every hour, at :17"; null when
     *  there is no simple pattern (the caller then falls back to the count). Hundreds of "more times" say less than this. */
    public static function pattern(array $starts, int $days): ?string
    {
        $min = []; $hour = []; $day = [];
        foreach ($starts as $t) { $min[date('i', $t)] = 1; $hour[date('G', $t)] = 1; $day[date('Y-m-d', $t)] = 1; }
        if (count($min) !== 1) return null;
        $m = array_key_first($min);
        if (count($hour) === 24) return "every hour, at :$m";
        $hs = array_keys($hour); sort($hs);
        $at = implode(', ', array_map(fn($h) => sprintf('%02d:%s', $h, $m), $hs));
        if (count($hour) <= 6 && count($day) >= 0.8 * $days) return "every day at $at";
        if (count($hour) <= 6) return "on " . count($day) . " of the next $days days, at $at";
        return null;
    }

    private static function spinWords(int $v): string
    {
        // Unraid's spindownDelay: 15, 30, 45 are minutes; 1-9 are hours (DiskSettings.page)
        return $v >= 15 ? "$v minutes" : ($v === 1 ? '1 hour' : "$v hours");
    }
}
