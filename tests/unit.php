<?php
/* Unit tests: the cron parser, the run-time expansion, the parity guards, and the overlap and warning rules.
 * Run: php tests/unit.php  (exit 0 = all passed). Times are in Europe/Helsinki so a clock change is covered. */
date_default_timezone_set('Europe/Helsinki');
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-timetable/include';
require_once "$P/Cron.php";
require_once "$P/Analyse.php";

$fail = 0; $n = 0;
function ok(bool $c, string $what, $got = null): void
{
    global $fail, $n; $n++;
    if ($c) return;
    $fail++;
    echo "FAIL $what" . ($got === null ? '' : ' -> ' . json_encode($got)) . "\n";
}
function t(string $s): int { return strtotime($s); }
function hm(array $ts): array { return array_map(fn($t) => date('Y-m-d H:i', $t), $ts); }

// --- parser ---
$p = TtCron::parse('*/15 2-4 * * mon-fri');
ok(array_keys($p['min']) === [0, 15, 30, 45], 'step minutes', array_keys($p['min']));
ok(array_keys($p['hour']) === [2, 3, 4], 'hour range');
ok(array_keys($p['dow']) === [1, 2, 3, 4, 5], 'dow names');
$p = TtCron::parse('5/20 0 1,15 jan,jul 7');
ok(array_keys($p['min']) === [5, 25, 45], '"5/20" runs from 5 to the end', array_keys($p['min']));
ok(array_keys($p['dow']) === [0], 'dow 7 is Sunday');
ok(array_keys($p['mon']) === [1, 7], 'month names');
foreach (['* * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '5-1 * * * *', '*/0 * * * *', 'a * * * *', '1,,2 * * * *'] as $bad) {
    $e = null;
    try { TtCron::parse($bad); } catch (InvalidArgumentException $x) { $e = $x->getMessage(); }
    ok($e !== null, "rejects \"$bad\"");
}

// --- lines ---
$l = TtCron::splitLine('40 3 * * * /usr/local/sbin/mover start > /dev/null 2> >(logger -t move)');
ok($l['expr'] === '40 3 * * *' && str_starts_with($l['command'], '/usr/local/sbin/mover start'), 'split a plain line', $l);
ok(TtCron::splitLine('# Generated mover schedule:') === null, 'comment skipped');
ok(TtCron::splitLine('MAILTO=""') === null, 'variable skipped');
ok(TtCron::splitLine('   ') === null, 'blank skipped');
$l = TtCron::splitLine('@daily /usr/local/bin/x');
ok($l['expr'] === '0 0 * * *' && $l['macro'] === '@daily', '@daily', $l);
$l = TtCron::splitLine('@reboot /usr/local/bin/x');
ok($l['expr'] === '@reboot', '@reboot is an event');
$l = TtCron::splitLine('*/5 * * * * root /usr/bin/php x.php', true);
ok($l['user'] === 'root' && $l['command'] === '/usr/bin/php x.php', 'user field', $l);
$l = TtCron::splitLine('* * * /bin/true');
ok(isset($l['error']), 'too few fields flagged');

// --- expansion ---
$from = t('2026-11-09 00:00'); // a Monday
$r = TtCron::expand(TtCron::parse('40 3 * * *'), $from, $from + 7 * 86400);
ok(count($r) === 7 && date('H:i', $r[0]) === '03:40', 'daily x7', hm($r));
$r = TtCron::expand(TtCron::parse('0 0 1 * *'), t('2026-11-09'), t('2027-02-01'));
ok(hm($r) === ['2026-12-01 00:00', '2027-01-01 00:00'], 'monthly on the 1st', hm($r));
$r = TtCron::expand(TtCron::parse('0 12 14 * 5'), t('2026-11-01'), t('2026-12-01'));
ok(count($r) === 5 && in_array('2026-11-14 12:00', hm($r), true), 'dom OR dow when both are set (14th or Fridays)', hm($r));
$r = TtCron::expand(TtCron::parse('30 3 * * *'), t('2026-03-28 00:00'), t('2026-03-31 00:00'));
ok(!in_array('2026-03-29 03:30', hm($r), true) && count($r) === 2, '03:30 does not exist on the spring clock change day', hm($r));
$r = TtCron::expand(TtCron::parse('* * * * *'), $from, $from + 86400, 10);
ok(count($r) === 10, 'cap respected');

// --- parity guards, as update.parity.php writes them ---
$g = TtCron::guard('[[ $(date +%e -d +1day) -eq 1 ]] && /usr/local/sbin/mdcmd check NOCORRECT &> /dev/null || :');
ok($g['kind'] === 'lastday', 'guard last day');
$r = TtCron::expand(TtCron::parse('0 0 28-31 * *'), t('2027-01-01'), t('2027-04-01'), 100, $g);
ok(hm($r) === ['2027-01-31 00:00', '2027-02-28 00:00', '2027-03-31 00:00'], 'last day of each month', hm($r));
$g = TtCron::guard('[[ $(date +%e -d -7days) -le 7 ]] && /usr/local/sbin/mdcmd check');
ok($g['kind'] === 'week' && $g['from'] === 8, 'guard week 2');
$r = TtCron::expand(TtCron::parse('0 1 * * 0'), t('2026-11-01'), t('2026-12-01'), 100, $g);
ok(hm($r) === ['2026-11-08 01:00'], 'second Sunday of November 2026', hm($r));
$g = TtCron::guard('[[ $(date +%e -d +7days) -le 7 ]] && x');
$r = TtCron::expand(TtCron::parse('0 1 * * 0'), t('2026-11-01'), t('2026-12-01'), 100, $g);
ok(hm($r) === ['2026-11-29 01:00'], 'last Sunday of November 2026', hm($r));
$g = TtCron::guard('[[ $(date +%e) -le 7 ]] && x');
$r = TtCron::expand(TtCron::parse('0 1 * * 1'), t('2026-11-01'), t('2026-12-01'), 100, $g);
ok(hm($r) === ['2026-11-02 01:00'], 'first Monday', hm($r));
$g = TtCron::guard('[[ $(((($(date +%s)-$(grep -Pom1 "^sbSynced=\K.*" /proc/mdstat))/86400)%7)) -eq 0 ]] && /usr/local/emhttp/webGui/scripts/parity_control resume &> /dev/null || :');
ok($g['kind'] === 'every7', 'guard every 7th day');
$r = TtCron::expand(TtCron::parse('0 1 * * *'), t('2026-11-01'), t('2026-11-30'), 100, $g, t('2026-11-03 12:00'));
ok(hm($r) === ['2026-11-03 01:00', '2026-11-10 01:00', '2026-11-17 01:00', '2026-11-24 01:00'], 'every 7th day from the base', hm($r));

// --- words ---
ok(TtCron::describe(TtCron::parse('40 3 * * *')) === 'every day at 03:40', 'describe daily', TtCron::describe(TtCron::parse('40 3 * * *')));
ok(TtCron::describe(TtCron::parse('*/5 * * * *')) === 'every 5 minutes', 'describe */5', TtCron::describe(TtCron::parse('*/5 * * * *')));
ok(TtCron::describe(TtCron::parse('11 0 * * 1')) === 'on Mondays at 00:11', 'describe weekly', TtCron::describe(TtCron::parse('11 0 * * 1')));
ok(TtCron::describe(TtCron::parse('0 0 1 * *')) === 'on day 1 of the month at 00:00', 'describe monthly', TtCron::describe(TtCron::parse('0 0 1 * *')));
ok(TtCron::describe(TtCron::parse('*/7 * * * *')) === 'every 7 minutes', 'describe */7', TtCron::describe(TtCron::parse('*/7 * * * *')));
ok(TtCron::describe(TtCron::parse('0 */6 * * *')) === 'every day at 00:00, 06:00, 12:00, 18:00', 'describe */6 hours', TtCron::describe(TtCron::parse('0 */6 * * *')));
ok(TtCron::describe(TtCron::parse('5 */5 * * *')) === 'every 5 hours at minute 5', 'describe */5 hours', TtCron::describe(TtCron::parse('5 */5 * * *')));
ok(TtCron::describe(TtCron::parse('47 * * * *')) === 'every hour at minute 47', 'describe hourly', TtCron::describe(TtCron::parse('47 * * * *')));

// --- analysis ---
function job(string $id, string $cat, string $expr, int $dur, array $impact, string $title = ''): array
{
    return ['id' => $id, 'title' => $title ?: $id, 'owner' => 'test', 'category' => $cat,
        'schedule' => ['type' => 'cron', 'expr' => $expr], 'impact' => $impact, 'duration' => ['sec' => $dur, 'basis' => 'default']];
}
$now = t('2026-11-09 00:00');
$jobs = [
    job('parity', 'parity', '0 1 * * 0', 8 * 3600, ['array_io', 'wakes_disks', 'needs_array'], 'Parity check'),
    job('mover', 'mover', '40 3 * * *', 3600, ['array_io', 'pool_io', 'wakes_disks', 'needs_array'], 'Mover'),
    job('trim', 'trim', '0 4 * * 0', 600, ['pool_io'], 'TRIM'),
    job('backup', 'backup', '0 5 * * 0', 2 * 3600, ['stops_containers', 'array_io', 'needs_array'], 'Appdata backup'),
    job('script', 'scripts', '40 3 * * 3', 300, ['unknown'], 'my script'),
    job('monitor', 'checks', '*/1 * * * *', 5, [], 'Monitor'),
    job('hourly', 'other', '0 * * * *', 2 * 3600, ['array_io'], 'slow hourly'),
];
TtAnalyse::prepare($jobs);
ok($jobs[5]['frequent'] === true && $jobs[1]['frequent'] === false, 'every-minute job is background work');
$w = TtAnalyse::windows($jobs, $now, $now + 7 * 86400);
ok(!in_array('monitor', array_column($w, 0), true), 'background jobs are not drawn');
$ws = TtAnalyse::warnings($jobs, $now, 7, ['startArray' => 'no', 'spindownDelay' => '30']);
$rules = [];
foreach ($ws as $x) $rules[$x['rule'] . ':' . implode(',', $x['jobs'])] = $x;
ok(isset($rules['mover-parity:parity,mover']), 'mover inside the parity window', array_keys($rules));
ok(isset($rules['trim-parity:parity,trim']), 'TRIM inside the parity window');
ok(isset($rules['backup-parity:parity,backup']), 'backup inside the parity window');
ok(!isset($rules['backup-mover:backup,mover']), 'backup at 05:00 starts after the mover (03:40 + 1 h) ends');
ok(isset($rules['same-start:mover,script']), 'user script at the mover\'s minute', array_keys($rules));
ok(isset($rules['self:hourly,hourly']), 'a 2 h job every hour overlaps itself');
ok(isset($rules['array-manual:parity,mover,backup']), 'array not started at boot names the jobs that need it', array_keys($rules));
ok(isset($rules['spinup:mover']) === false && isset($rules['spinup:parity']) === false, 'daily jobs do not count as frequent wake-ups');
$mp = $rules['mover-parity:parity,mover'];
ok(date('D H:i', $mp['window'][0]) === 'Sun 03:40' && date('H:i', $mp['window'][1]) === '04:40', 'overlap window is the shared time', [date('D H:i', $mp['window'][0]), date('H:i', $mp['window'][1])]);
ok($mp['severity'] === 'warning' && str_contains($mp['title'], 'Parity check') && str_contains($mp['title'], 'Mover'), 'title names both jobs', $mp['title']);
ok($mp['why'] !== '' && str_starts_with($mp['example'], 'Ex.'), 'reason and example');
ok($ws[0]['severity'] === 'warning', 'warnings sort before info');
// no false positives: jobs a day apart
$quiet = [job('p', 'parity', '0 1 * * 0', 3600, ['array_io']), job('m', 'mover', '0 1 * * 1', 3600, ['array_io'])];
TtAnalyse::prepare($quiet);
ok(TtAnalyse::warnings($quiet, $now, 14) === [], 'no warning when nothing overlaps', TtAnalyse::warnings($quiet, $now, 14));
// back to back is not an overlap
$bb = [job('p', 'parity', '0 1 * * *', 3600, ['array_io']), job('m', 'mover', '0 2 * * *', 3600, ['array_io'])];
TtAnalyse::prepare($bb);
ok(TtAnalyse::warnings($bb, $now, 3) === [], 'end at 02:00 and start at 02:00 do not overlap');
// cluster: three light jobs at 00:00
$cl = [job('a', 'checks', '0 0 * * *', 60, []), job('b', 'checks', '1 0 * * *', 60, []), job('c', 'other', '3 0 * * *', 60, [])];
TtAnalyse::prepare($cl);
$cw = TtAnalyse::warnings($cl, $now, 2);
ok(count($cw) === 1 && $cw[0]['rule'] === 'cluster' && count($cw[0]['jobs']) === 3, 'three starts within 5 minutes make one cluster', $cw);
// spin-up: an hourly job that wakes disks with a 30 minute spin-down
$sp = [job('h', 'other', '0 * * * *', 60, ['wakes_disks'])];
TtAnalyse::prepare($sp);
$sw = TtAnalyse::warnings($sp, $now, 2, ['spindownDelay' => '30']);
ok(count($sw) === 1 && $sw[0]['rule'] === 'spinup' && $sw[0]['count'] === 24 && str_contains($sw[0]['why'], '30 minutes'), 'spin-up warning', $sw);
$sw = TtAnalyse::warnings($sp, $now, 2, ['spindownDelay' => '0']);
ok($sw === [], 'no spin-up warning when disks never spin down');
// next run
ok(date('Y-m-d H:i', TtAnalyse::nextRun($jobs[1], t('2026-11-09 04:00'))) === '2026-11-10 03:40', 'next run');
// a bad expression is reported, not fatal
$bad = [job('x', 'other', '61 * * * *', 60, [])];
TtAnalyse::prepare($bad);
ok(isset($bad[0]['schedule']['error']) && TtAnalyse::windows($bad, $now, $now + 86400) === [], 'bad expression flagged and skipped');

// --- 2026.11.12h rules ---
$rules = fn(array $ws) => array_map(fn($x) => $x['rule'] . ':' . implode(',', $x['jobs']), $ws);
$byRule = function (array $ws, string $r) { return array_values(array_filter($ws, fn($x) => $x['rule'] === $r)); };
// parity-repeat: Sunday and Monday ticked, a 23 h check: the Monday start comes 1 h after the end
$pr = [job('pc', 'parity', '0 23 * * 0,1', 23 * 3600, ['array_io'], 'Parity check')];
TtAnalyse::prepare($pr);
$pw = TtAnalyse::warnings($pr, $now, 14);
$x = $byRule($pw, 'parity-repeat');
ok(count($x) === 1 && $x[0]['severity'] === 'warning' && str_contains($x[0]['why'], '23 h') && str_starts_with($x[0]['example'], 'Ex.'), 'parity-repeat when the next start comes soon after the end', $rules($pw));
// a monthly check is not a repeat; a 26 h check on two days is a repeat and not "self"
$pr2 = [job('pc', 'parity', '0 23 * * 0,1', 26 * 3600, ['array_io'], 'Parity check')];
TtAnalyse::prepare($pr2);
$pw2 = TtAnalyse::warnings($pr2, $now, 14);
ok($byRule($pw2, 'parity-repeat') && !$byRule($pw2, 'self'), 'a check longer than the gap is parity-repeat, not self', $rules($pw2));
$pr3 = [job('pc', 'parity', '0 1 1 * *', 20 * 3600, ['array_io'], 'Parity check')];
TtAnalyse::prepare($pr3);
ok(!$byRule(TtAnalyse::warnings($pr3, $now, 70), 'parity-repeat'), 'a monthly check does not repeat');
// scrub-parity, scrub-balance
$sc = [job('pc', 'parity', '0 1 * * 0', 8 * 3600, ['array_io'], 'Parity check'),
    job('scr', 'pool', '0 2 * * 0', 7200, ['pool_io'], 'Scrub, pool fast (btrfs)') + ['pool' => 'fast', 'poolOp' => 'scrub'],
    job('bal', 'pool', '0 3 * * 0', 7200, ['pool_io'], 'Balance, pool fast (btrfs)') + ['pool' => 'fast', 'poolOp' => 'balance']];
TtAnalyse::prepare($sc);
$sw = TtAnalyse::warnings($sc, $now, 7);
$x = $byRule($sw, 'scrub-parity');
ok(count($x) === 2 && $x[0]['severity'] === 'warning' && str_contains($x[0]['title'], 'Parity check'), 'pool scrub and balance during a parity check are warnings', $rules($sw));
$x = $byRule($sw, 'scrub-balance');
ok(count($x) === 1 && $x[0]['severity'] === 'info' && !$byRule($sw, 'pool-pool'), 'scrub and balance of one pool are info, not pool-pool', $rules($sw));
// s3-sleep-during-run: starts at 02:00 (sleep excluded 01-03), still running at 04:00
$sl = [job('long', 'backup', '0 2 * * *', 4 * 3600, ['stops_containers'], 'Long backup'), job('short', 'other', '0 2 * * *', 1800, ['array_io'], 'short job'),
    ['id' => 's3', 'title' => 'S3 Sleep', 'owner' => 't', 'category' => 'sleep', 'impact' => [], 'schedule' => ['type' => 'daemon'], 'sleep' => ['service' => '1', 'stopHour' => [1, 2, 3], 'stopDay' => []]]];
TtAnalyse::prepare($sl);
$slw = TtAnalyse::warnings($sl, $now, 3);
$x = $byRule($slw, 's3-sleep-during-run');
ok(count($x) === 1 && in_array('long', $x[0]['jobs'], true) && !in_array('short', $x[0]['jobs'], true) && $x[0]['severity'] === 'warning', 's3-sleep-during-run names the job that runs into a sleep hour, warning for a backup', $rules($slw));
ok(!$byRule($slw, 's3-sleep'), 'jobs that start in excluded hours are not s3-sleep');
// Mover Tuning skips the scheduled mover during a check: mover-parity is info; a paused increment that still counts blocks the mover
$mt = [job('pc', 'parity', '0 1 * * 0', 8 * 3600, ['array_io'], 'Parity check'),
    job('mv', 'mover', '0 3 * * *', 1800, ['array_io'], 'Mover (Mover Tuning)') + ['skipsDuringCheck' => ['setting' => 'parity', 'pausedCounts' => false]]];
TtAnalyse::prepare($mt);
$mw = TtAnalyse::warnings($mt, $now, 14);
$x = $byRule($mw, 'mover-parity');
ok($x && $x[0]['severity'] === 'info' && str_contains($x[0]['why'], 'skipped'), 'mover-parity is info when the run is skipped during the check', $x[0] ?? $rules($mw));
ok(!$byRule($mw, 'mover-blocked-by-check'), 'an 8 h check does not block the mover for days');
$mt2 = $mt;
$mt2[] = job('inc', 'parity', '0 0 * * *', 6 * 3600, ['array_io'], 'Parity check, cumulative part') + ['conditional' => 'Runs only while a parity check is unfinished.'];
$mt2[1]['skipsDuringCheck']['pausedCounts'] = true;
TtAnalyse::prepare($mt2);
$x = $byRule(TtAnalyse::warnings($mt2, $now, 14), 'mover-blocked-by-check');
ok(count($x) === 1 && $x[0]['severity'] === 'warning' && str_contains($x[0]['why'], 'paused'), 'an older Mover Tuning counts a paused increment check as running', $x);
$mt2[1]['skipsDuringCheck']['pausedCounts'] = false;
ok(!$byRule(TtAnalyse::warnings($mt2, $now, 14), 'mover-blocked-by-check'), 'a current Mover Tuning runs the mover while the check is paused');
// duplicate lines: same command from two files
$du = [job('a', 'backup', '0 3 * * 0', 3600, ['stops_containers'], 'Appdata backup') + ['command' => 'php /x/backup.php', 'source' => ['file' => '/a.cron', 'line' => 2, 'live' => '/etc/cron.d/root:5']],
    job('a2', 'backup', '0 3 * * 0', 3600, ['stops_containers'], 'Appdata backup') + ['command' => 'php /x/backup.php', 'source' => ['file' => '/var/spool/cron/crontabs/root', 'line' => 9, 'live' => '/var/spool/cron/crontabs/root:9']]];
TtAnalyse::prepare($du);
$dw = TtAnalyse::warnings($du, $now, 7, ['duplicates' => [['a', 'a2']]]);
ok(count($byRule($dw, 'duplicate')) === 1 && $byRule($dw, 'duplicate')[0]['severity'] === 'warning' && !$byRule($dw, 'containers-twice'), 'duplicate line warns once and hides the pair finding with itself', $rules($dw));

echo ($fail ? "FAIL $fail of $n" : "ok   unit: $n checks") . "\n";
exit($fail ? 1 : 0);
